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

/**
 * Validates golden fixtures captured from the live module against the vendored UCP spec.
 * Fixtures are scrubbed of volatile values by FixtureScrubber.
 */
class ShoppingSchemaConformanceTest extends TestCase
{
    use SchemaAssert;

    private const CHECKOUT_SCHEMA = 'shopping/checkout_resp.json';
    private const ERROR_SCHEMA = 'shopping/types/error_response.json';
    private const CART_SCHEMA = 'shopping/cart_resp.json';
    private const SEARCH_SCHEMA = 'shopping/catalog_search.json#/$defs/search_response';
    private const LOOKUP_SCHEMA = 'shopping/catalog_lookup.json#/$defs/lookup_response';
    private const PRODUCT_SCHEMA = 'shopping/catalog_lookup.json#/$defs/get_product_response';
    private const ORDER_SCHEMA = 'shopping/order_resp.json';
    private const ORDER_FIXTURE = 'shopping.order.get.200.json';
    private const SHIPPED_ORDER_FIXTURE = 'shopping.order.get.shipped_refunded.200.json';

    /**
     * Response fields the spec puts in an extension, mapped to the capability that declares them.
     */
    private const EXTENSION_FIELDS = [
        'fulfillment' => 'dev.ucp.shopping.fulfillment',
        'discounts' => 'dev.ucp.shopping.discount',
    ];

    /**
     * @return array<string, array{0: string}>
     */
    public static function checkoutFixtureProvider(): array
    {
        return [
            'create 201' => ['shopping.checkout_session.create.201.json'],
            'get 200' => ['shopping.checkout_session.get.200.json'],
            'update 200' => ['shopping.checkout_session.update.200.json'],
            'cancel 200' => ['shopping.checkout_session.cancel.200.json'],
        ];
    }

    /**
     * @dataProvider checkoutFixtureProvider
     * @param string $fixture
     * @return void
     */
    public function testCheckoutResponseMatchesSpec(string $fixture): void
    {
        $this->assertMatchesSchema(self::loadFixtureObject($fixture), self::CHECKOUT_SCHEMA);
    }

    /**
     * @return void
     */
    public function testDiscoveryProfileMatchesSpec(): void
    {
        $this->assertMatchesSchema(
            self::loadFixtureObject('discovery.well_known_ucp.200.json')->ucp,
            'ucp.json#/$defs/business_schema'
        );
    }

    /**
     * @return void
     */
    public function testCompleteResponseMatchesSpec(): void
    {
        $this->assertMatchesSchema(
            self::loadFixtureObject('shopping.checkout_session.complete.200.json'),
            self::CHECKOUT_SCHEMA
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function orderFixtureProvider(): array
    {
        return [
            'placed 200' => [self::ORDER_FIXTURE],
            'shipped and partly refunded 200' => [self::SHIPPED_ORDER_FIXTURE],
        ];
    }

    /**
     * @dataProvider orderFixtureProvider
     * @param string $fixture
     * @return void
     */
    public function testOrderResponseMatchesSpec(string $fixture): void
    {
        $this->assertMatchesSchema(self::loadFixtureObject($fixture), self::ORDER_SCHEMA);
    }

    /**
     * Every id an order references has to name a line item the same order lists, or an agent reading the
     * event log cannot tell which item shipped.
     *
     * @dataProvider orderFixtureProvider
     * @param string $fixture
     * @return void
     */
    public function testEveryReferencedLineItemExists(string $fixture): void
    {
        $order = self::loadFixture($fixture);
        $known = array_column($order['line_items'], 'id');

        foreach ($order['fulfillment']['expectations'] ?? [] as $expectation) {
            $this->assertSame([], array_diff(array_column($expectation['line_items'], 'id'), $known));
        }

        foreach ($order['fulfillment']['events'] ?? [] as $event) {
            $this->assertSame([], array_diff(array_column($event['line_items'], 'id'), $known));
        }

        foreach ($order['adjustments'] ?? [] as $adjustment) {
            $this->assertSame([], array_diff(array_column($adjustment['line_items'] ?? [], 'id'), $known));
        }
    }

    /**
     * A shipment is an event with tracking, and a refund is an adjustment that moves money the other
     * way — both signed so the direction is in the value.
     *
     * @return void
     */
    public function testAShippedAndRefundedOrderReportsBothLogs(): void
    {
        $order = self::loadFixture(self::SHIPPED_ORDER_FIXTURE);
        $event = $order['fulfillment']['events'][0];
        $adjustment = $order['adjustments'][0];

        $this->assertSame('shipped', $event['type']);
        $this->assertNotEmpty($event['tracking_number']);
        $this->assertSame('refund', $adjustment['type']);
        $this->assertLessThan(0, $adjustment['totals'][0]['amount']);
        $this->assertLessThan(0, $adjustment['line_items'][0]['quantity']);
    }

    /**
     * A returned quantity cannot still be in the buyer's hands, so what is reported fulfilled never
     * exceeds what is still on the order.
     *
     * @return void
     */
    public function testFulfilledQuantityNeverExceedsTheActiveQuantity(): void
    {
        foreach (self::loadFixture(self::SHIPPED_ORDER_FIXTURE)['line_items'] as $lineItem) {
            $this->assertLessThanOrEqual($lineItem['quantity']['total'], $lineItem['quantity']['fulfilled']);
        }
    }

    /**
     * The order is the continuation of a session the agent already holds, so it has to name that
     * session and keep the line item identifiers the checkout gave it.
     *
     * @return void
     */
    public function testTheOrderReconcilesWithTheCheckoutThatProducedIt(): void
    {
        $checkout = self::loadFixture('shopping.checkout_session.complete.200.json');
        $order = self::loadFixture(self::ORDER_FIXTURE);

        $this->assertSame($checkout['id'], $order['checkout_id']);
        $this->assertSame($checkout['order']['id'], $order['id']);
        $this->assertSame($checkout['order']['permalink_url'], $order['permalink_url']);
        $this->assertSame(
            array_column($checkout['line_items'], 'id'),
            array_column($order['line_items'], 'id')
        );
    }

    /**
     * An order nothing has shipped for still tells the agent what to expect, and says the log is empty
     * rather than leaving it out.
     *
     * @return void
     */
    public function testAnUnshippedOrderReportsExpectationsAndAnEmptyEventLog(): void
    {
        $order = self::loadFixture(self::ORDER_FIXTURE);

        $this->assertNotEmpty($order['fulfillment']['expectations']);
        $this->assertSame([], $order['fulfillment']['events']);
        $this->assertSame('processing', $order['line_items'][0]['status']);
    }

    /**
     * An agent only sends for capabilities it can discover, so a populated extension must be advertised.
     *
     * @dataProvider checkoutFixtureProvider
     * @param string $fixture
     * @return void
     */
    public function testEveryPopulatedExtensionIsAdvertised(string $fixture): void
    {
        $payload = self::loadFixture($fixture);
        $advertised = array_keys($payload['ucp']['capabilities']);

        foreach (self::EXTENSION_FIELDS as $field => $capability) {
            if (($payload[$field] ?? null) === null) {
                continue;
            }

            $this->assertContains($capability, $advertised, sprintf('"%s" is populated', $field));
        }
    }

    /**
     * An extension has to name the capability it extends; a root capability has to leave it out.
     *
     * @return void
     */
    public function testExtensionsDeclareWhatTheyExtend(): void
    {
        $capabilities = self::loadFixture('shopping.checkout_session.get.200.json')['ucp']['capabilities'];

        foreach (self::EXTENSION_FIELDS as $capability) {
            $this->assertSame(
                'dev.ucp.shopping.checkout',
                $capabilities[$capability][0]['extends'] ?? null,
                sprintf('"%s" declares its parent', $capability)
            );
        }

        $this->assertArrayNotHasKey('extends', $capabilities['dev.ucp.shopping.checkout'][0]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function cartFixtureProvider(): array
    {
        return [
            'create 201' => ['shopping.cart.create.201.json'],
            'get 200' => ['shopping.cart.get.200.json'],
            'update 200' => ['shopping.cart.update.200.json'],
            'cancel 200' => ['shopping.cart.cancel.200.json'],
        ];
    }

    /**
     * @dataProvider cartFixtureProvider
     * @param string $fixture
     * @return void
     */
    public function testCartResponseMatchesSpec(string $fixture): void
    {
        $this->assertMatchesSchema(self::loadFixtureObject($fixture), self::CART_SCHEMA);
    }

    /**
     * A cart is a smaller resource than a checkout, not a checkout with fields blanked. None of these
     * belong on it, and its `ucp` block declares no payment handlers.
     *
     * @dataProvider cartFixtureProvider
     * @param string $fixture
     * @return void
     */
    public function testACartCarriesNoCheckoutOnlyFields(string $fixture): void
    {
        $cart = self::loadFixture($fixture);

        foreach (['payment', 'status', 'fulfillment', 'order', 'cart_id'] as $field) {
            $this->assertArrayNotHasKey($field, $cart, sprintf('"%s" is not a cart field', $field));
        }

        $this->assertArrayNotHasKey('payment_handlers', $cart['ucp']);
    }

    /**
     * Checkout reports missing buyer details as errors because it cannot proceed without them. A cart can,
     * so the same findings are informational.
     *
     * @dataProvider cartFixtureProvider
     * @param string $fixture
     * @return void
     */
    public function testACartReportsValidationFindingsAsInformational(string $fixture): void
    {
        foreach (self::loadFixture($fixture)['messages'] ?? [] as $message) {
            $this->assertSame('info', $message['type']);
        }
    }

    /**
     * A canceled cart is gone rather than a cart in a canceled state, so a later read is an error
     * envelope and not a cart.
     *
     * @return void
     */
    public function testReadingACanceledCartIsAnError(): void
    {
        $payload = self::loadFixture('shopping.cart.get.404.json');

        $this->assertMatchesSchema(self::loadFixtureObject('shopping.cart.get.404.json'), self::ERROR_SCHEMA);
        $this->assertSame('not_found', $payload['messages'][0]['code']);
    }

    /**
     * @return void
     */
    public function testCatalogSearchMatchesSpec(): void
    {
        $this->assertMatchesSchema(self::loadFixtureObject('shopping.catalog.search.200.json'), self::SEARCH_SCHEMA);
    }

    /**
     * @return void
     */
    public function testCatalogLookupMatchesSpec(): void
    {
        $this->assertMatchesSchema(self::loadFixtureObject('shopping.catalog.lookup.200.json'), self::LOOKUP_SCHEMA);
    }

    /**
     * @return void
     */
    public function testCatalogProductMatchesSpec(): void
    {
        $this->assertMatchesSchema(self::loadFixtureObject('shopping.catalog.product.200.json'), self::PRODUCT_SCHEMA);
    }

    /**
     * An agent buys a variant, so every product carries at least one even when it has no option axes.
     *
     * @return void
     */
    public function testEveryProductHasAVariant(): void
    {
        foreach (self::loadFixture('shopping.catalog.search.200.json')['products'] as $product) {
            $this->assertNotEmpty($product['variants'], $product['id'] . ' has a variant');
        }
    }

    /**
     * An out-of-stock product is reported rather than hidden, so an agent learns it exists and cannot be
     * bought instead of being told nothing. Magento would drop it from the page but not from the count.
     *
     * @return void
     */
    public function testOutOfStockProductsAreReportedNotHidden(): void
    {
        $search = self::loadFixture('shopping.catalog.search.200.json');
        $statuses = [];

        foreach ($search['products'] as $product) {
            $statuses[] = $product['variants'][0]['availability']['status'];
        }

        $this->assertContains('out_of_stock', $statuses);
        $this->assertCount($search['pagination']['total_count'], $search['products']);
    }

    /**
     * A lookup variant has to say which requested identifier resolved to it.
     *
     * @return void
     */
    public function testLookupCorrelatesEveryVariantToTheRequestedId(): void
    {
        foreach (self::loadFixture('shopping.catalog.lookup.200.json')['products'] as $product) {
            foreach ($product['variants'] as $variant) {
                $this->assertNotEmpty($variant['inputs']);
                $this->assertContains($variant['inputs'][0]['match'], ['exact', 'featured']);
            }
        }
    }

    /**
     * An unknown id is left out of a lookup rather than failing the batch, but a direct product read for
     * one is an error.
     *
     * @return void
     */
    public function testAnUnknownProductIsAnError(): void
    {
        $payload = self::loadFixture('shopping.catalog.product.404.json');

        $this->assertMatchesSchema(self::loadFixtureObject('shopping.catalog.product.404.json'), self::ERROR_SCHEMA);
        $this->assertSame('not_found', $payload['messages'][0]['code']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function errorFixtureProvider(): array
    {
        return [
            'unknown session 404' => ['shopping.checkout_session.get.404.json'],
            'invalid body 400' => ['shopping.checkout_session.create.400.json'],
        ];
    }

    /**
     * The error envelope forbids additional properties, so a captured failure is the only thing that
     * proves the boundary is not still sending its own invented fields.
     *
     * @dataProvider errorFixtureProvider
     * @param string $fixture
     * @return void
     */
    public function testErrorResponseMatchesSpec(string $fixture): void
    {
        $this->assertMatchesSchema(self::loadFixtureObject($fixture), self::ERROR_SCHEMA);
    }

    /**
     * @return void
     */
    public function testAValidationErrorPointsAtTheOffendingFieldAsAJsonPath(): void
    {
        $payload = self::loadFixture('shopping.checkout_session.create.400.json');

        $this->assertSame('$.line_items', $payload['messages'][0]['path']);
    }

    /**
     * @return void
     */
    public function testCompletedSessionCarriesTheOrder(): void
    {
        $payload = self::loadFixture('shopping.checkout_session.complete.200.json');

        $this->assertSame('completed', $payload['status']);
        $this->assertNotEmpty($payload['order']['id']);
        $this->assertNotEmpty($payload['order']['permalink_url']);
        $this->assertSame([], $payload['messages']);
    }
}
