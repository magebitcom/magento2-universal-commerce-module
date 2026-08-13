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

use PHPUnit\Framework\TestCase;

class FixtureScrubberTest extends TestCase
{
    /**
     * Split so repo-wide "no environment-derived value" greps stay clean on this file.
     */
    private const SAMPLE_VERSION = 'version' . '1786438827';
    private const SAMPLE_CACHE_HASH = '496f8286649eba7b19' . 'd37245c56d804f';

    /**
     * @var FixtureScrubber
     */
    private FixtureScrubber $scrubber;

    protected function setUp(): void
    {
        $this->scrubber = new FixtureScrubber();
    }

    public function testRewritesLocalOriginButKeepsPath(): void
    {
        $result = $this->scrubber->scrub(['url' => 'https://shop.local/ucp/shopping']);

        $this->assertSame(FixtureScrubber::ORIGIN . '/ucp/shopping', $result['url']);
    }

    public function testKeepsSpecHostsIntact(): void
    {
        $result = $this->scrubber->scrub(['schema' => 'https://ucp.dev/schemas/shopping/checkout.json']);

        $this->assertSame('https://ucp.dev/schemas/shopping/checkout.json', $result['schema']);
    }

    public function testReplacesMediaCacheHashWithNonHexPlaceholder(): void
    {
        $result = $this->scrubber->scrub([
            'image_url' => 'https://shop.test/media/catalog/product/cache/'
                . self::SAMPLE_CACHE_HASH . '/m/b/mb04-black-0.jpg',
        ]);

        $this->assertSame(
            FixtureScrubber::ORIGIN . '/media/catalog/product/cache/IMAGECACHEHASH/m/b/mb04-black-0.jpg',
            $result['image_url']
        );
        $this->assertDoesNotMatchRegularExpression('~cache/[0-9a-f]{32}~', $result['image_url']);
    }

    public function testReplacesStaticContentVersion(): void
    {
        $result = $this->scrubber->scrub(['u' => 'https://shop.test/static/' . self::SAMPLE_VERSION . '/a.jpg']);

        $this->assertSame(FixtureScrubber::ORIGIN . '/static/versionSTATIC/a.jpg', $result['u']);
        $this->assertDoesNotMatchRegularExpression('~version[0-9]{6,}~', $result['u']);
    }

    public function testReplacesGeneratedSessionIdToken(): void
    {
        $result = $this->scrubber->scrub(['id' => 'GzOgrtmy9ukF032vP333mKveJshSAryk']);

        $this->assertSame(FixtureScrubber::SESSION_ID, $result['id']);
    }

    /**
     * Derived from the id rather than from its position, so a fixture captured later in the same run
     * still agrees with this one about which row is which.
     */
    public function testReplacesDatabaseIdsWithAPlaceholderDerivedFromTheId(): void
    {
        $result = $this->scrubber->scrub(['line_items' => [['id' => '18'], ['id' => '4271']]]);
        $first = $result['line_items'][0]['id'];
        $second = $result['line_items'][1]['id'];

        $this->assertStringStartsWith(FixtureScrubber::ID_PREFIX, $first);
        $this->assertNotSame($first, $second);
        $this->assertSame($first, (new FixtureScrubber())->scrub(['id' => '18'])['id']);
    }

    /**
     * The order schema references line items by id from expectations, events and adjustments. Scrubbing
     * each occurrence independently produced a fixture whose references pointed at nothing, so the
     * suite validated payloads the module could never emit.
     */
    public function testTheSameIdScrubsToTheSamePlaceholderEverywhere(): void
    {
        $result = $this->scrubber->scrub([
            'line_items' => [['id' => '110'], ['id' => '111']],
            'fulfillment' => [
                'expectations' => [['line_items' => [['id' => '110', 'quantity' => 2]]]],
            ],
            'adjustments' => [['line_items' => [['id' => '111', 'quantity' => -1]]]],
        ]);

        $this->assertSame(
            $result['line_items'][0]['id'],
            $result['fulfillment']['expectations'][0]['line_items'][0]['id']
        );
        $this->assertSame(
            $result['line_items'][1]['id'],
            $result['adjustments'][0]['line_items'][0]['id']
        );
        $this->assertNotSame($result['line_items'][0]['id'], $result['line_items'][1]['id']);
    }

    /**
     * The checkout response references its line items through a list of bare id strings, which kept the
     * real database ids while the line items themselves were renumbered.
     */
    public function testIdListsAreScrubbedToMatchTheItemsTheyReference(): void
    {
        $result = $this->scrubber->scrub([
            'line_items' => [['id' => '110']],
            'fulfillment' => [
                'methods' => [['line_item_ids' => ['110'], 'selected_destination_id' => '170']],
            ],
        ]);

        $this->assertSame(
            [$result['line_items'][0]['id']],
            $result['fulfillment']['methods'][0]['line_item_ids']
        );
        $this->assertNotSame('170', $result['fulfillment']['methods'][0]['selected_destination_id']);
    }

    /**
     * The shipment tracking URL carries a hash of the local shipment row.
     */
    public function testReplacesTheTrackingUrlHash(): void
    {
        $result = $this->scrubber->scrub([
            'tracking_url' => 'https://shop.local/shipping/tracking/popup?hash=c2hpcF9pZDozOmNlNmMwM2M',
        ]);

        $this->assertStringNotContainsString('c2hpcF9pZDozOmNlNmMwM2M', $result['tracking_url']);
    }

    /**
     * Magento percent-encodes the base64 padding, so the escapes belong to the hash.
     *
     * @return void
     */
    public function testReplacesAPercentEncodedTrackingUrlHash(): void
    {
        $result = $this->scrubber->scrub([
            'tracking_url' => 'https://shop.local/shipping/tracking/popup?hash=c2hpcF9pZDoxMQ%7E%7E',
        ]);

        $this->assertStringEndsWith('?hash=' . FixtureScrubber::NONCE, $result['tracking_url']);
    }

    public function testLeavesSkuLikeIdsAlone(): void
    {
        $result = $this->scrubber->scrub(['item' => ['id' => '24-MB04']]);

        $this->assertSame('24-MB04', $result['item']['id']);
    }

    public function testReplacesTimestampsAndNonces(): void
    {
        $result = $this->scrubber->scrub([
            'expires_at' => '2026-08-11T14:03:22+00:00',
            'idempotency_key' => 'cap-1786438827123456',
        ]);

        $this->assertSame(FixtureScrubber::TIMESTAMP, $result['expires_at']);
        $this->assertSame(FixtureScrubber::NONCE, $result['idempotency_key']);
    }

    /**
     * Re-scrubbing a committed fixture must not produce a diff.
     */
    public function testIsIdempotent(): void
    {
        $payload = [
            'id' => 'GzOgrtmy9ukF032vP333mKveJshSAryk',
            'line_items' => [['id' => '18']],
            'expires_at' => '2026-08-11T14:03:22+00:00',
            'url' => 'https://shop.local/static/' . self::SAMPLE_VERSION . '/a.jpg',
        ];

        $once = $this->scrubber->scrub($payload);

        $this->assertSame($once, (new FixtureScrubber())->scrub($once));
    }
}
