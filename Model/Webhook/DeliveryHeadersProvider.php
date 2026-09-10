<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\Webhook;

use Magebit\AgenticCore\Api\Data\WebhookDeliveryInterface;
use Magebit\AgenticCore\Api\SigningKeyRepositoryInterface;
use Magebit\AgenticCore\Api\Webhook\DeliveryHeadersProviderInterface;
use Magebit\AgenticCore\Model\Signature\MessageSigner;
use Magebit\UniversalCommerce\Model\IdempotencyHandler;
use Magebit\UniversalCommerce\Model\Config;

/**
 * This protocol's order-event headers, signed per RFC 9421 because the spec requires it of webhooks.
 */
class DeliveryHeadersProvider implements DeliveryHeadersProviderInterface
{
    /**
     * Body digest per RFC 9530.
     */
    private const DIGEST_HEADER = 'Content-Digest';

    /**
     * The event's own identifier and occurrence time, both fixed when it happened: a retry is the same
     * event, so neither may move between attempts.
     */
    private const EVENT_ID_HEADER = 'Webhook-Id';
    private const EVENT_TIMESTAMP_HEADER = 'Webhook-Timestamp';

    /**
     * Identifies the sender by its own discovery profile, in RFC 8941 dictionary syntax.
     */
    private const AGENT_HEADER = 'UCP-Agent';

    /**
     * The discovery path is fixed by the spec, so the profile URI is the module's own API base plus it.
     */
    private const PROFILE_PATH = '/.well-known/ucp';

    /**
     * The Content-Type the sender attaches. It is signed, so the two have to agree exactly.
     */
    private const CONTENT_TYPE = 'application/json';

    /**
     * The key set is published at one profile per site, which is the default scope the profile URL is
     * itself resolved in.
     */
    private const KEY_STORE_ID = 0;

    /**
     * @param Config $config
     * @param SigningKeyRepositoryInterface $signingKeys
     * @param MessageSigner $signer
     */
    public function __construct(
        private readonly Config $config,
        private readonly SigningKeyRepositoryInterface $signingKeys,
        private readonly MessageSigner $signer
    ) {
    }

    /**
     * @inheritDoc
     * @throws \RuntimeException
     */
    public function getHeaders(WebhookDeliveryInterface $delivery, int $attemptTimestamp): array
    {
        $agent = sprintf('profile="%s"', $this->config->getApiBaseUrl() . self::PROFILE_PATH);
        $digest = $this->digestOf((string) $delivery->getPayload());

        $headers = [
            self::DIGEST_HEADER => $digest,
            self::EVENT_ID_HEADER => (string) $delivery->getReference(),
            self::EVENT_TIMESTAMP_HEADER => (string) $this->occurredAt($delivery),
            self::AGENT_HEADER => $agent,
        ];

        return $headers + $this->signatureFor((string) $delivery->getUrl(), $agent, $digest);
    }

    /**
     * @param string $url Where this delivery is going
     * @param string $agent
     * @param string $digest
     * @return array<string, string>
     * @throws \RuntimeException
     */
    private function signatureFor(string $url, string $agent, string $digest): array
    {
        $key = $this->signingKeys->getSigningKey(IdempotencyHandler::SCOPE, self::KEY_STORE_ID);
        $pem = $key->getPrivateKeyPem();

        if ($pem === null) {
            throw new \RuntimeException('The signing key for order events could not be read.');
        }

        return $this->signer->sign(
            [
                '@method' => 'POST',
                // The receiver's host and path, not ours: a signature over our own would still verify
                // after the delivery was relayed somewhere else.
                '@authority' => $this->authorityOf($url),
                '@path' => $this->pathOf($url),
                'ucp-agent' => $agent,
                'content-digest' => $digest,
                'content-type' => self::CONTENT_TYPE,
            ],
            (string) $key->getKid(),
            $pem
        );
    }

    /**
     * Host lowercased with the default port dropped, per RFC 9421.
     *
     * @param string $url
     * @return string
     */
    private function authorityOf(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $port = parse_url($url, PHP_URL_PORT);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $isDefault = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);

        return $port === null || $isDefault ? $host : $host . ':' . $port;
    }

    /**
     * @param string $url
     * @return string
     */
    private function pathOf(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return $path === '' ? '/' : $path;
    }

    /**
     * @param string $payload
     * @return string
     */
    private function digestOf(string $payload): string
    {
        return 'sha-256=:' . base64_encode(hash('sha256', $payload, true)) . ':';
    }

    /**
     * When the event happened, not when this attempt is being made. Falls back to the attempt only if
     * the row somehow carries no creation time, which the schema does not allow.
     *
     * @param WebhookDeliveryInterface $delivery
     * @return int
     */
    private function occurredAt(WebhookDeliveryInterface $delivery): int
    {
        $createdAt = (string) $delivery->getCreatedAt();

        return $createdAt === '' ? 0 : (int) strtotime($createdAt . ' UTC');
    }
}
