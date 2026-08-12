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

        if (!$ucpAgentHeader) {
            return $platformConfig;
        }

        $profileUri = $this->extractProfileUri($ucpAgentHeader);
        if (!$profileUri) {
            return $platformConfig;
        }

        try {
            $profileData = $this->fetchProfileData($profileUri);
            if (!$profileData) {
                return $platformConfig;
            }

            $webhookUrl = $this->extractWebhookUrl($profileData);
            if (!$webhookUrl) {
                return $platformConfig;
            }

            return $platformConfig->setWebhookUrl($webhookUrl);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch or parse agent profile', [
                'exception' => $e->getMessage(),
                'uri' => $profileUri
            ]);
            return $platformConfig;
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
        if (!isset($profileData['ucp']['capabilities']) || !is_array($profileData['ucp']['capabilities'])) {
            return null;
        }

        foreach ($profileData['ucp']['capabilities'] as $capability) {
            if (!is_array($capability)) {
                continue;
            }

            if (($capability['name'] ?? null) === 'dev.ucp.shopping.order' &&
                isset($capability['config']['webhook_url'])
            ) {
                return $capability['config']['webhook_url'];
            }
        }

        return null;
    }
}
