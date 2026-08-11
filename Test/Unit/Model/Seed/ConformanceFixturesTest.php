<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit\Model\Seed;

use Magebit\UniversalCommerce\Model\Seed\ConformanceFixtures;
use Magento\SalesRule\Model\Rule;
use PHPUnit\Framework\TestCase;

/**
 * The conformance run asserts exact totals against these fixtures, so the definitions are pinned
 * here — a silent edit to a price or a discount would show up as an unexplained conformance failure.
 */
class ConformanceFixturesTest extends TestCase
{
    /**
     * @return void
     */
    public function testEverySkuIsSeededExactlyOnce(): void
    {
        $skus = array_column(ConformanceFixtures::products(), 'sku');

        $this->assertSame([
            ConformanceFixtures::SKU_SIMPLE,
            ConformanceFixtures::SKU_SECOND,
            ConformanceFixtures::SKU_OUT_OF_STOCK,
        ], $skus);
        $this->assertSame($skus, array_unique($skus));
    }

    /**
     * The out-of-stock path is a required conformance scenario, so exactly one fixture supplies it.
     *
     * @return void
     */
    public function testExactlyOneProductIsOutOfStock(): void
    {
        $outOfStock = array_values(array_filter(
            ConformanceFixtures::products(),
            static fn (array $p): bool => $p['in_stock'] === false
        ));

        $this->assertCount(1, $outOfStock);
        $this->assertSame(ConformanceFixtures::SKU_OUT_OF_STOCK, $outOfStock[0]['sku']);
        $this->assertSame(0, $outOfStock[0]['qty']);
    }

    /**
     * @return void
     */
    public function testInStockProductsCarryStock(): void
    {
        foreach (ConformanceFixtures::products() as $product) {
            if ($product['in_stock']) {
                $this->assertGreaterThan(0, $product['qty'], $product['sku'] . ' must have stock');
            }
        }
    }

    /**
     * @return void
     */
    public function testPricesAreFixedSoTotalsCanBeAsserted(): void
    {
        $prices = array_column(ConformanceFixtures::products(), 'price', 'sku');

        $this->assertSame(20.00, $prices[ConformanceFixtures::SKU_SIMPLE]);
        $this->assertSame(35.50, $prices[ConformanceFixtures::SKU_SECOND]);
        $this->assertSame(15.00, $prices[ConformanceFixtures::SKU_OUT_OF_STOCK]);
    }

    /**
     * @return void
     */
    public function testTheThreeExpectedCouponsAreDefined(): void
    {
        $codes = array_column(ConformanceFixtures::rules(), 'code');

        $this->assertSame([
            ConformanceFixtures::COUPON_PERCENT_10,
            ConformanceFixtures::COUPON_PERCENT_20,
            ConformanceFixtures::COUPON_FIXED_500,
        ], $codes);
    }

    /**
     * `FIXED500` is 500 minor units, which Magento expresses as 5.00 major units — the conversion
     * that makes this fixture easy to get wrong.
     *
     * @return void
     */
    public function testDiscountActionsAndAmountsMatchTheirNames(): void
    {
        $rules = array_column(ConformanceFixtures::rules(), null, 'code');

        $this->assertSame(Rule::BY_PERCENT_ACTION, $rules[ConformanceFixtures::COUPON_PERCENT_10]['action']);
        $this->assertSame(10.0, $rules[ConformanceFixtures::COUPON_PERCENT_10]['amount']);

        $this->assertSame(Rule::BY_PERCENT_ACTION, $rules[ConformanceFixtures::COUPON_PERCENT_20]['action']);
        $this->assertSame(20.0, $rules[ConformanceFixtures::COUPON_PERCENT_20]['amount']);

        $this->assertSame(Rule::CART_FIXED_ACTION, $rules[ConformanceFixtures::COUPON_FIXED_500]['action']);
        $this->assertSame(5.00, $rules[ConformanceFixtures::COUPON_FIXED_500]['amount']);
    }
}
