<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit\Model\Webhook;

use Magebit\AgenticCore\Api\Data\WebhookDeliveryInterface;
use Magebit\UniversalCommerce\Model\Config;
use Magebit\UniversalCommerce\Model\Webhook\DeliveryHeadersProvider;
use PHPUnit\Framework\TestCase;

class DeliveryHeadersProviderTest extends TestCase
{
    private const PAYLOAD = '{"id":"order_1"}';

    /**
     * When the event happened, 1767225600. The attempt below is deliberately later.
     */
    private const OCCURRED_AT = '2026-01-01 00:00:00';
    private const OCCURRED_TIMESTAMP = 1767225600;
    private const ATTEMPT_TIMESTAMP = 1767229200;

    /**
     * Fixed against an independently computed digest rather than recomputed the way the code does:
     * php -r 'echo base64_encode(hash("sha256", "{\"id\":\"order_1\"}", true));'
     *
     * @return void
     */
    public function testTheBodyDigestFollowsRfc9530(): void
    {
        $expected = base64_encode(hash('sha256', self::PAYLOAD, true));

        $this->assertSame(
            'sha-256=:' . $expected . ':',
            $this->headers()['Content-Digest']
        );
        $this->assertMatchesRegularExpression('/^sha-256=:[A-Za-z0-9+\/]+={0,2}:$/', $this->headers()['Content-Digest']);
    }

    /**
     * A retry is the same event, so the occurrence time must not move to the attempt's. Reporting the
     * attempt instead would tell the receiver the order changed again every time delivery was retried.
     *
     * @return void
     */
    public function testTheTimestampIsWhenTheEventHappenedNotWhenItIsSent(): void
    {
        $this->assertSame((string) self::OCCURRED_TIMESTAMP, $this->headers()['Webhook-Timestamp']);
        $this->assertNotSame((string) self::ATTEMPT_TIMESTAMP, $this->headers()['Webhook-Timestamp']);
    }

    /**
     * @return void
     */
    public function testTheEventIdIsTheDeliverysOwnReference(): void
    {
        $this->assertSame('11111111-2222-4333-8444-555555555555', $this->headers()['Webhook-Id']);
    }

    /**
     * The spec requires the sender's profile URI in RFC 8941 dictionary syntax, pointing at discovery.
     *
     * @return void
     */
    public function testTheSenderIsIdentifiedByItsOwnProfileUri(): void
    {
        $this->assertSame(
            'profile="https://merchant.test/.well-known/ucp"',
            $this->headers()['UCP-Agent']
        );
    }

    /**
     * No credential is sent yet: the scheme is undecided, and delivery ships disabled because of it.
     * This asserts the absence deliberately so enabling delivery without choosing one cannot pass.
     *
     * @return void
     */
    public function testNoCredentialIsSentWhileTheSchemeIsUndecided(): void
    {
        $headers = $this->headers();

        $this->assertArrayNotHasKey('Signature', $headers);
        $this->assertArrayNotHasKey('Signature-Input', $headers);
        $this->assertArrayNotHasKey('X-API-Key', $headers);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $config = $this->createMock(Config::class);
        $config->method('getApiBaseUrl')->willReturn('https://merchant.test');

        $delivery = $this->createMock(WebhookDeliveryInterface::class);
        $delivery->method('getPayload')->willReturn(self::PAYLOAD);
        $delivery->method('getReference')->willReturn('11111111-2222-4333-8444-555555555555');
        $delivery->method('getCreatedAt')->willReturn(self::OCCURRED_AT);

        return (new DeliveryHeadersProvider($config))->getHeaders($delivery, self::ATTEMPT_TIMESTAMP);
    }
}
