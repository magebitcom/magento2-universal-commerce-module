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
use Magebit\AgenticCore\Api\Webhook\DeliveryHeadersProviderInterface;
use Magebit\UniversalCommerce\Model\Config;

/**
 * This protocol's order-event headers. Note what it does not carry: the spec offers three ways to
 * authenticate the call — RFC 9421 message signatures, a platform-allocated `X-API-Key`, or neither —
 * and the merchant has not chosen one, so no credential is sent and delivery ships disabled. Adding
 * the chosen scheme is one more entry in this array.
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
     * @param Config $config
     */
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getHeaders(WebhookDeliveryInterface $delivery, int $attemptTimestamp): array
    {
        return [
            self::DIGEST_HEADER => $this->digestOf((string) $delivery->getPayload()),
            self::EVENT_ID_HEADER => (string) $delivery->getReference(),
            self::EVENT_TIMESTAMP_HEADER => (string) $this->occurredAt($delivery),
            self::AGENT_HEADER => sprintf('profile="%s"', $this->config->getApiBaseUrl() . self::PROFILE_PATH),
        ];
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
