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
    private const ORDER_SCHEMA = 'shopping/order_resp.json';
    private const ORDER_FIXTURE = 'shopping.order.get.200.json';

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
     * @return void
     */
    public function testOrderResponseMatchesSpec(): void
    {
        $this->assertMatchesSchema(self::loadFixtureObject(self::ORDER_FIXTURE), self::ORDER_SCHEMA);
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
