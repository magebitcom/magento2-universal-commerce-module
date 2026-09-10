<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit;

/**
 * Normalises environment-derived values out of captured API responses so fixtures are
 * portable between developer setups and produce stable diffs. Every new fixture goes
 * through this class — do not hand-edit a fixture.
 */
class FixtureScrubber
{
    public const ORIGIN = 'https://merchant.example';
    public const SESSION_ID = 'checkout_session_placeholder_0001';
    public const CACHE_HASH = 'IMAGECACHEHASH';
    public const STATIC_VERSION = 'versionSTATIC';
    public const TIMESTAMP = '2026-01-01T00:00:00+00:00';
    public const NONCE = 'nonce-placeholder';

    /**
     * Hosts that are part of the protocol spec rather than the local deployment.
     */
    private const SPEC_HOSTS = ['ucp.dev', 'json-schema.org', 'schema.org'];

    /**
     * Keys whose values are nonces / idempotency keys echoed back by the API.
     */
    private const NONCE_KEYS = ['idempotency_key', 'nonce', 'request_id', 'trace_id', 'signature'];

    /**
     * Placeholders are chosen so re-running the scrubber is a no-op, and so that no placeholder
     * itself looks like the machine-specific value it replaced (e.g. the cache hash is not hex).
     */
    private const ENTITY_ID = '1000';

    /**
     * @var array<int, array{0: string, 1: string}>
     */

    private const PATTERNS = [
        // Media cache hash: rotates whenever image config changes.
        ['~/media/catalog/product/cache/[0-9a-f]{32}/~', '/media/catalog/product/cache/' . self::CACHE_HASH . '/'],
        // Shipment tracking URL hash. The base64 arrives percent-encoded, so the escapes count as part
        // of the hash — without them a tail of the local value survives.
        ['~([?&]hash)=(?:[A-Za-z0-9+/=_-]|%[0-9A-Fa-f]{2})+~', '$1=' . self::NONCE],
        // Static content deploy version: changes on every setup:static-content:deploy.
        ['~/static/version\d+/~', '/static/' . self::STATIC_VERSION . '/'],
        // ISO-8601 / RFC 3339 timestamps.
        ['~\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?~', self::TIMESTAMP],
        // Local entity ids embedded in permalink paths.
        ['~/(order_id|quote_id|customer_id)/\d+~', '/$1/' . self::ENTITY_ID],
    ];

    /**
     * @var int
     */
    /**
     * Prefix marking a scrubbed id. It is not numeric, so a second pass leaves it alone and a placeholder
     * can never be mistaken for the row id it replaced.
     */
    public const ID_PREFIX = 'id-';

    /**
     * Returns a copy of the payload with every environment-derived value replaced.
     *
     * Accepts stdClass as well as arrays. Decoding a fixture to associative arrays
     * collapses `{}` to `[]`, which would hide exactly the array-vs-object defects
     * these fixtures exist to catch, so object nodes must survive the round trip.
     *
     * @param array<mixed>|object $payload
     * @return array<mixed>|object
     */
    public function scrub(array|object $payload): array|object
    {
        return $this->scrubNode($payload);
    }

    /**
     * @param mixed $node
     * @return mixed
     */
    private function scrubNode(mixed $node): mixed
    {
        if (is_object($node)) {
            $result = new \stdClass();

            foreach (get_object_vars($node) as $key => $value) {
                $result->$key = $this->scrubMember($key, $value);
            }

            return $result;
        }

        if (is_array($node)) {
            $result = [];

            foreach ($node as $key => $value) {
                $result[$key] = $this->scrubMember((string) $key, $value);
            }

            return $result;
        }

        return $node;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    private function scrubMember(string $key, mixed $value): mixed
    {
        // A list of bare id strings references the same rows the ids do, so it is mapped the same way.
        if (is_array($value) && str_ends_with($key, '_ids')) {
            return array_map(
                fn (mixed $item): mixed => is_string($item) ? $this->scrubId($item) : $this->scrubMember($key, $item),
                $value
            );
        }

        return match (true) {
            is_array($value), is_object($value) => $this->scrubNode($value),
            is_string($value) => $this->scrubValue($key, $value),
            default => $value,
        };
    }

    /**
     * @param string $key
     * @param string $value
     * @return string
     */
    private function scrubValue(string $key, string $value): string
    {
        if (in_array($key, self::NONCE_KEYS, true)) {
            return self::NONCE;
        }

        // Opaque 32-char tokens are generated checkout session ids.
        if (preg_match('~^[A-Za-z0-9]{32}$~', $value) === 1) {
            return self::SESSION_ID;
        }

        if ($key === 'id' || str_ends_with($key, '_id')) {
            return $this->scrubId($value);
        }

        $value = $this->scrubOrigin($value);

        foreach (self::PATTERNS as [$pattern, $replacement]) {
            $value = (string) preg_replace($pattern, $replacement, $value);
        }

        return $value;
    }

    /**
     * Rewrites the origin of any absolute URL that is not a protocol/spec URL, keeping the path.
     *
     * @param string $value
     * @return string
     */
    private function scrubOrigin(string $value): string
    {
        return (string) preg_replace_callback(
            '~https?://[^/\s"]+~',
            static function (array $match): string {
                $host = (string) parse_url($match[0], PHP_URL_HOST);

                return in_array($host, self::SPEC_HOSTS, true) ? $match[0] : self::ORIGIN;
            },
            $value
        );
    }

    /**
     * A database id becomes a placeholder derived from the id itself rather than from its position, so
     * that every reference to it — in the same fixture or in another captured from the same run — scrubs
     * to the same value. Anything not purely numeric, a SKU or a reverse-domain handler name, is left
     * alone.
     *
     * @param string $value
     * @return string
     */
    private function scrubId(string $value): string
    {
        if (preg_match('~^\d+$~', $value) !== 1) {
            return $value;
        }

        return self::ID_PREFIX . substr(hash('sha256', $value), 0, 8);
    }
}
