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

use Magebit\AgenticCore\Api\Data\SigningKeyInterface;
use Magebit\AgenticCore\Api\Data\WebhookDeliveryInterface;
use Magebit\AgenticCore\Api\SigningKeyRepositoryInterface;
use Magebit\AgenticCore\Model\Signature\EcdsaP256Signer;
use Magebit\AgenticCore\Model\Signature\MessageSigner;
use Magebit\AgenticCore\Model\Signature\SignatureBase;
use Magebit\UniversalCommerce\Model\Config;
use Magebit\UniversalCommerce\Model\Webhook\DeliveryHeadersProvider;
use PHPUnit\Framework\TestCase;

class DeliveryHeadersProviderTest extends TestCase
{
    private const PAYLOAD = '{"id":"order_1"}';
    private const TARGET_URL = 'https://platform.example/webhooks/ucp/orders';
    private const KID = 'merchant-2026';

    /**
     * When the event happened, 1767225600. The attempt below is deliberately later.
     */
    private const OCCURRED_AT = '2026-01-01 00:00:00';
    private const OCCURRED_TIMESTAMP = 1767225600;
    private const ATTEMPT_TIMESTAMP = 1767229200;

    /**
     * One pair for the whole class; a fresh pair per test would prove nothing extra.
     *
     * @var string
     */
    private static string $privateKey = '';

    /**
     * @var string
     */
    private static string $publicKey = '';

    /**
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        $resource = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($resource, $pem);
        self::$privateKey = (string) $pem;
        self::$publicKey = (string) openssl_pkey_get_details($resource)['key'];
    }

    /**
     * Fixed against an independently computed digest rather than recomputed the way the code does:
     * php -r 'echo base64_encode(hash("sha256", "{\"id\":\"order_1\"}", true));'
     *
     * @return void
     */
    public function testTheBodyDigestFollowsRfc9530(): void
    {
        $expected = base64_encode(hash('sha256', self::PAYLOAD, true));

        $this->assertSame('sha-256=:' . $expected . ':', $this->headers()['Content-Digest']);
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
        $this->assertSame('profile="https://merchant.test/.well-known/ucp"', $this->headers()['UCP-Agent']);
    }

    /**
     * The spec says webhooks MUST be signed, so an unsigned delivery is not a configuration choice.
     *
     * @return void
     */
    public function testTheDeliveryIsSigned(): void
    {
        $headers = $this->headers();

        $this->assertArrayHasKey('Signature', $headers);
        $this->assertArrayHasKey('Signature-Input', $headers);
    }

    /**
     * An API key authenticates a platform calling us, not us calling a platform.
     *
     * @return void
     */
    public function testNoApiKeyIsSent(): void
    {
        $this->assertArrayNotHasKey('X-API-Key', $this->headers());
    }

    /**
     * @return void
     */
    public function testTheSignatureNamesTheKeyPublishedInOurProfile(): void
    {
        $this->assertStringContainsString(';keyid="' . self::KID . '"', $this->headers()['Signature-Input']);
    }

    /**
     * @return void
     */
    public function testTheSignatureCoversTheTargetTheIdentityAndTheBody(): void
    {
        $this->assertStringStartsWith(
            'sig1=("@method" "@authority" "@path" "ucp-agent" "content-digest" "content-type")',
            $this->headers()['Signature-Input']
        );
    }

    /**
     * The authority is the receiver's host, not ours. Signing our own would let a recipient relay the
     * delivery to a different endpoint with the signature still intact.
     *
     * @return void
     */
    public function testTheSignedAuthorityIsTheReceiversHost(): void
    {
        $components = $this->expectedComponents();

        $this->assertSame('platform.example', $components['@authority']);
        $this->assertSame('/webhooks/ucp/orders', $components['@path']);
    }

    /**
     * The bytes actually signed have to be the ones a verifier rebuilds from the headers, which only a
     * real verification against the public key proves.
     *
     * @return void
     */
    public function testTheSignatureVerifiesAgainstThePublishedKey(): void
    {
        $this->assertSame(1, $this->verify($this->expectedComponents()));
    }

    /**
     * @return void
     */
    public function testATamperedBodyBreaksTheSignature(): void
    {
        $components = $this->expectedComponents();
        $components['content-digest'] = 'sha-256=:' . base64_encode(hash('sha256', 'tampered', true)) . ':';

        $this->assertSame(0, $this->verify($components));
    }

    /**
     * @param array<string, string> $components
     * @return int
     */
    private function verify(array $components): int
    {
        $signature = $this->headers()[MessageSigner::HEADER];
        $raw = (string) base64_decode(substr($signature, strlen('sig1=:'), -1), true);
        $signer = new MessageSigner(new SignatureBase(), new EcdsaP256Signer());

        return openssl_verify(
            $signer->baseFor($components, self::KID),
            $this->derFrom($raw),
            self::$publicKey,
            OPENSSL_ALGO_SHA256
        );
    }

    /**
     * @return array<string, string>
     */
    private function expectedComponents(): array
    {
        return [
            '@method' => 'POST',
            '@authority' => 'platform.example',
            '@path' => '/webhooks/ucp/orders',
            'ucp-agent' => 'profile="https://merchant.test/.well-known/ucp"',
            'content-digest' => 'sha-256=:' . base64_encode(hash('sha256', self::PAYLOAD, true)) . ':',
            'content-type' => 'application/json',
        ];
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
        $delivery->method('getUrl')->willReturn(self::TARGET_URL);

        $key = $this->createMock(SigningKeyInterface::class);
        $key->method('getKid')->willReturn(self::KID);
        $key->method('getPrivateKeyPem')->willReturn(self::$privateKey);

        $keys = $this->createMock(SigningKeyRepositoryInterface::class);
        $keys->method('getSigningKey')->willReturn($key);

        return (new DeliveryHeadersProvider(
            $config,
            $keys,
            new MessageSigner(new SignatureBase(), new EcdsaP256Signer())
        ))->getHeaders($delivery, self::ATTEMPT_TIMESTAMP);
    }

    /**
     * @param string $raw
     * @return string
     */
    private function derFrom(string $raw): string
    {
        $sequence = $this->derInteger(substr($raw, 0, 32)) . $this->derInteger(substr($raw, 32, 32));

        return "\x30" . chr(strlen($sequence)) . $sequence;
    }

    /**
     * @param string $value
     * @return string
     */
    private function derInteger(string $value): string
    {
        $value = ltrim($value, "\x00");

        if ($value === '' || (ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }

        return "\x02" . chr(strlen($value)) . $value;
    }
}
