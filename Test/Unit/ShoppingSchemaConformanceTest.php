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
