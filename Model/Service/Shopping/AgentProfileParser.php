<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\Service\Shopping;

use Magebit\UcpSpec\Api\Shopping\OrderResponsePlatformSchemaInterface;
use Magebit\UcpSpec\Api\Shopping\OrderResponsePlatformSchemaInterfaceFactory;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

/**
 * Agent Profile Parser
 * Parses UCP-Agent header to extract agent profile information
 */
class AgentProfileParser
{
    /**
     * Capability whose platform-side config carries the URL order events are sent to.
     */
    private const ORDER_CAPABILITY = 'dev.ucp.shopping.order';

    private const CACHE_PREFIX = 'ucp_agent_profile_';
    private const CACHE_LIFETIME = 3600; // 1 hour
    private const CACHE_TAG = 'ucp_agent_profile';
    private const HTTP_TIMEOUT = 5;
    private const MAX_REDIRECTS = 3;
    private const MAX_BODY_BYTES = 262144;
    private const MAX_DATA_URI_BYTES = 262144;

    /**
     * @param OrderResponsePlatformSchemaInterfaceFactory $platformSchemaFactory
     * @param CurlFactory $curlFactory
     * @param CacheInterface $cache
     * @param LoggerInterface $logger
     * @param ProfileUrlValidator $urlValidator
     */
    public function __construct(
        private readonly OrderResponsePlatformSchemaInterfaceFactory $platformSchemaFactory,
        private readonly CurlFactory $curlFactory,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly ProfileUrlValidator $urlValidator
    ) {
    }

    /**
     * Parse UCP agent profile from header
     *
     * @param string|null $ucpAgentHeader
     * @return OrderResponsePlatformSchemaInterface
     */
    public function parse(?string $ucpAgentHeader = null): OrderResponsePlatformSchemaInterface
    {
        $platformConfig = $this->platformSchemaFactory->create();
        $webhookUrl = $this->parseWebhookUrl($ucpAgentHeader);

        // Left unset rather than blank when the profile declares none: the generated field is
        // required-typed, so writing null would make every later read raise.
        return $webhookUrl === null ? $platformConfig : $platformConfig->setWebhookUrl($webhookUrl);
    }

    /**
     * The agent's own order-event URL, or null when it declares none. Callers want the field far more
     * often than the schema around it, and the schema's getter raises on an absent value.
     *
     * @param string|null $ucpAgentHeader
     * @return string|null
     */
    public function parseWebhookUrl(?string $ucpAgentHeader = null): ?string
    {
        if (!$ucpAgentHeader) {
            return null;
        }

        $profileUri = $this->extractProfileUri($ucpAgentHeader);

        if (!$profileUri) {
            return null;
        }

        try {
            $profileData = $this->fetchProfileData($profileUri);

            return $profileData ? $this->extractWebhookUrl($profileData) : null;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch or parse agent profile', [
                'exception' => $e->getMessage(),
                'uri' => $profileUri
            ]);

            return null;
        }
    }

    /**
     * Extract profile URI from UCP-Agent header
     *
     * @param string $ucpAgentHeader
     * @return string|null
     */
    private function extractProfileUri(string $ucpAgentHeader): ?string
    {
        if (!preg_match('/profile="([^"]+)"/', $ucpAgentHeader, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Fetch profile data from URI
     *
     * @param string $profileUri
     * @return array<string, mixed>|null
     */
    private function fetchProfileData(string $profileUri): ?array
    {
        if (str_starts_with($profileUri, 'data:')) {
            return $this->parseDataUri($profileUri);
        }

        if (str_starts_with($profileUri, 'http://') || str_starts_with($profileUri, 'https://')) {
            return $this->fetchHttpProfile($profileUri);
        }

        return null;
    }

    /**
     * Parse data URI and extract JSON
     *
     * @param string $dataUri
     * @return array<string, mixed>|null
     */
    private function parseDataUri(string $dataUri): ?array
    {
        $parts = explode(',', $dataUri, 2);
        if (!isset($parts[1])) {
            return null;
        }

        if (strlen($parts[1]) > self::MAX_DATA_URI_BYTES) {
            return null;
        }

        $jsonStr = base64_decode($parts[1], true);
        if ($jsonStr === false) {
            return null;
        }

        $data = json_decode($jsonStr, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Fetch profile from HTTP URL with caching
     *
     * @param string $url
     * @return array<string, mixed>|null
     */
    private function fetchHttpProfile(string $url): ?array
    {
        $cacheKey = self::CACHE_PREFIX . sha1($url);

        // Check cache first
        $cachedData = $this->cache->load($cacheKey);
        if ($cachedData !== false) {
            $data = json_decode($cachedData, true);
            return is_array($data) ? $data : null;
        }

        $response = $this->fetchValidated($url);

        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return null;
        }

        // Cache the response
        $this->cache->save(
            $response,
            $cacheKey,
            [self::CACHE_TAG],
            self::CACHE_LIFETIME
        );

        return $data;
    }

    /**
     * Follow redirects by hand so every hop is re-validated, not just the first.
     *
     * @param string $url
     * @return string|null
     */
    private function fetchValidated(string $url): ?string
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $addresses = $this->urlValidator->assertFetchable($url);

            $curl = $this->curlFactory->create();
            $curl->setTimeout(self::HTTP_TIMEOUT);
            // @phpstan-ignore argument.type
            $curl->setOption(CURLOPT_FOLLOWLOCATION, false);
            // @phpstan-ignore argument.type
            $curl->setOption(CURLOPT_RESOLVE, $this->pinResolution($url, $addresses));
            $curl->get($url);

            $status = $curl->getStatus();

            if ($status === 200) {
                $body = $curl->getBody();

                return strlen($body) > self::MAX_BODY_BYTES ? null : $body;
            }

            if (!in_array($status, [301, 302, 303, 307, 308], true)) {
                return null;
            }

            $location = $curl->getHeaders()['location'] ?? $curl->getHeaders()['Location'] ?? null;

            if (!is_string($location) || $location === '') {
                return null;
            }

            $url = $location;
        }

        return null;
    }

    /**
     * @param string $url
     * @param string[] $addresses
     * @return string[]
     */
    private function pinResolution(string $url, array $addresses): array
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $port = $parts['port'] ?? 443;

        return [sprintf('%s:%d:%s', $host, $port, implode(',', $addresses))];
    }

    /**
     * Extract webhook URL from profile data
     *
     * @param array<string, mixed> $profileData
     * @return string|null
     */
    private function extractWebhookUrl(array $profileData): ?string
    {
        $ucp = $profileData['ucp'] ?? null;
        $capabilities = is_array($ucp) ? ($ucp['capabilities'] ?? null) : null;
        $entries = is_array($capabilities) ? ($capabilities[self::ORDER_CAPABILITY] ?? null) : null;

        if (!is_array($entries)) {
            return null;
        }

        // The registry is keyed by reverse-domain name and each value is a list of entries, so the name
        // is the key and no entry carries one. A platform may declare several entries for one capability.
        foreach ($entries as $entry) {
            if (is_array($entry) && isset($entry['config']['webhook_url'])) {
                $url = $entry['config']['webhook_url'];

                if (is_string($url) && $url !== '' && $this->isCallableWebhookUrl($url)) {
                    return $url;
                }
            }
        }

        return null;
    }

    /**
     * The store posts to this address later, so it needs the same guard as the profile URL or it can
     * point back inside our own network. A bad one is dropped, not fatal: the agent can still shop.
     *
     * @param string $url
     * @return bool
     */
    private function isCallableWebhookUrl(string $url): bool
    {
        try {
            $this->urlValidator->assertFetchable($url);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Dropped an agent webhook URL the store is not allowed to call', [
                'exception' => $e->getMessage(),
                'url' => $url
            ]);

            return false;
        }
    }
}
