<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit\Model\Service\Shopping\Converter;

use Magebit\UcpSpec\Api\Shopping\Types\TotalResponseInterface;
use Magebit\UniversalCommerce\Api\Data\TotalTypeInterface;
use Magebit\UcpSpec\Api\Shopping\Types\TotalResponseInterfaceFactory;
use Magebit\AgenticCore\Model\Money\MinorUnits;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\QuoteToTotalsResponse;
use Magebit\UcpSpec\Data\Shopping\Types\TotalResponse;
use Magebit\UniversalCommerce\Test\Unit\Model\Stub\TotalRow;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Api\Data\CurrencyInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class QuoteToTotalsResponseTest extends TestCase
{
    private const MAPPING = [
        'subtotal' => TotalTypeInterface::TYPE_SUBTOTAL,
        'discount' => TotalTypeInterface::TYPE_DISCOUNT,
        'shipping_discount' => TotalTypeInterface::TYPE_DISCOUNT,
        'shipping' => TotalTypeInterface::TYPE_FULFILLMENT,
        'tax' => TotalTypeInterface::TYPE_TAX,
        'grand_total' => TotalTypeInterface::TYPE_TOTAL,
    ];

    /** @var QuoteToTotalsResponse */
    private QuoteToTotalsResponse $converter;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $factory = $this->createMock(TotalResponseInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(fn (): TotalResponse => new TotalResponse());

        $this->converter = new QuoteToTotalsResponse($factory, new MinorUnits(), self::MAPPING);
    }

    /**
     * The sign is intrinsic to the value: `total_resp` types discount amounts as `signed_amount` with
     * `exclusiveMaximum: 0`, so a positive discount is a schema violation rather than a presentation
     * choice. The 2026-01-23 snapshot typed every amount `minimum: 0`, which is why this reversed.
     *
     * @return void
     */
    public function testDiscountIsEmittedAsANegativeAmount(): void
    {
        $totals = $this->convert([
            ['subtotal', 'Subtotal', 100.00],
            ['discount', 'Discount', -15.00],
            ['grand_total', 'Grand Total', 85.00],
        ]);

        $this->assertSame(-1500, $totals[TotalTypeInterface::TYPE_DISCOUNT]);
    }

    /**
     * Magento's own sign is not consistent between total rows, so the type decides the sign rather
     * than the value that arrived.
     *
     * @return void
     */
    public function testAPositiveMagentoDiscountRowIsStillEmittedNegative(): void
    {
        $totals = $this->convert([
            ['subtotal', 'Subtotal', 100.00],
            ['discount', 'Discount', 15.00],
            ['grand_total', 'Grand Total', 85.00],
        ]);

        $this->assertSame(-1500, $totals[TotalTypeInterface::TYPE_DISCOUNT]);
    }

    /**
     * `subtotal`, `fulfillment`, `tax` and `fee` carry `minimum: 0`, so only discounts may be negative.
     *
     * @return void
     */
    public function testOnlyDiscountAmountsAreNegative(): void
    {
        $totals = $this->convert([
            ['subtotal', 'Subtotal', 100.00],
            ['discount', 'Discount', -15.00],
            ['shipping_discount', 'Shipping Discount', -5.00],
            ['shipping', 'Shipping', 7.50],
            ['tax', 'Tax', 4.25],
            ['grand_total', 'Grand Total', 91.75],
        ]);

        foreach ($totals as $type => $amount) {
            if ($type === TotalTypeInterface::TYPE_DISCOUNT) {
                $this->assertLessThan(0, $amount);
                continue;
            }

            $this->assertGreaterThanOrEqual(0, $amount);
        }
    }

    /**
     * Two Magento codes mapping to one spec type must not lose money.
     *
     * @return void
     */
    public function testCodesSharingATypeAreSummed(): void
    {
        $totals = $this->convert([
            ['subtotal', 'Subtotal', 100.00],
            ['discount', 'Discount', -15.00],
            ['shipping_discount', 'Shipping Discount', -5.00],
            ['grand_total', 'Grand Total', 80.00],
        ]);

        $this->assertSame(-2000, $totals[TotalTypeInterface::TYPE_DISCOUNT]);
    }

    /**
     * An unmapped code would emit a value outside the spec's type enum.
     *
     * @return void
     */
    public function testUnmappedCodeIsDropped(): void
    {
        $totals = $this->convert([
            ['subtotal', 'Subtotal', 100.00],
            ['reward_points', 'Reward Points', -10.00],
            ['grand_total', 'Grand Total', 90.00],
        ]);

        $this->assertSame(
            [TotalTypeInterface::TYPE_SUBTOTAL, TotalTypeInterface::TYPE_TOTAL],
            array_keys($totals)
        );
    }

    /**
     * @return void
     */
    public function testSubtotalAndTotalAppearExactlyOnce(): void
    {
        $response = $this->converter->convert($this->quote([
            ['subtotal', 'Subtotal', 100.00],
            ['subtotal', 'Subtotal Again', 100.00],
            ['grand_total', 'Grand Total', 100.00],
        ]));

        $types = array_map(fn (TotalResponseInterface $t): string => $t->getType(), $response);

        $this->assertSame(1, array_count_values($types)[TotalTypeInterface::TYPE_SUBTOTAL]);
        $this->assertSame(1, array_count_values($types)[TotalTypeInterface::TYPE_TOTAL]);
    }

    /**
     * @return void
     */
    public function testMissingSubtotalFallsBackToTheAddress(): void
    {
        $totals = $this->convert([['grand_total', 'Grand Total', 42.00]], 37.50);

        $this->assertSame(3750, $totals[TotalTypeInterface::TYPE_SUBTOTAL]);
        $this->assertSame(4200, $totals[TotalTypeInterface::TYPE_TOTAL]);
    }

    /**
     * @return void
     */
    public function testEmissionOrderFollowsTheSpec(): void
    {
        $response = $this->converter->convert($this->quote([
            ['grand_total', 'Grand Total', 80.00],
            ['tax', 'Tax', 5.00],
            ['discount', 'Discount', -15.00],
            ['subtotal', 'Subtotal', 90.00],
        ]));

        $this->assertSame(
            [
                TotalTypeInterface::TYPE_SUBTOTAL,
                TotalTypeInterface::TYPE_DISCOUNT,
                TotalTypeInterface::TYPE_TAX,
                TotalTypeInterface::TYPE_TOTAL,
            ],
            array_map(fn (TotalResponseInterface $t): string => $t->getType(), $response)
        );
    }

    /**
     * @param array<array{0: string, 1: string, 2: float}> $rows
     * @param float $addressSubtotal
     * @return array<string, int>
     */
    private function convert(array $rows, float $addressSubtotal = 0.0): array
    {
        $result = [];

        foreach ($this->converter->convert($this->quote($rows, $addressSubtotal)) as $total) {
            $result[$total->getType()] = $total->getAmount();
        }

        return $result;
    }

    /**
     * Magento takes a coupon off the grand total without always emitting a discount row to go with it.
     * Left alone the response shows a cheaper order with nothing to explain it.
     *
     * @return void
     */
    public function testADiscountOnTheAddressIsReportedWhenMagentoEmitsNoRow(): void
    {
        $quote = $this->quote([
            ['subtotal', 'Subtotal', 20.00],
            ['grand_total', 'Grand Total', 18.00],
        ], addressDiscount: -2.00);

        $totals = $this->converter->convert($quote);
        $discount = $this->byType($totals, TotalTypeInterface::TYPE_DISCOUNT);

        $this->assertNotNull($discount, 'the discount was dropped from the response');
        $this->assertSame(-200, $discount->getAmount());
    }

    /**
     * @return void
     */
    public function testTheAddressDiscountIsIgnoredWhenMagentoAlreadyEmittedOne(): void
    {
        $quote = $this->quote([
            ['subtotal', 'Subtotal', 20.00],
            ['discount', 'Discount (10OFF)', -2.00],
            ['grand_total', 'Grand Total', 18.00],
        ], addressDiscount: -2.00);

        $discount = $this->byType($this->converter->convert($quote), TotalTypeInterface::TYPE_DISCOUNT);

        $this->assertNotNull($discount);
        $this->assertSame(-200, $discount->getAmount());
        $this->assertSame('Discount (10OFF)', $discount->getDisplayText());
    }

    /**
     * @return void
     */
    public function testNoDiscountTotalWhenNothingWasDiscounted(): void
    {
        $quote = $this->quote([
            ['subtotal', 'Subtotal', 20.00],
            ['grand_total', 'Grand Total', 20.00],
        ]);

        $this->assertNull($this->byType($this->converter->convert($quote), TotalTypeInterface::TYPE_DISCOUNT));
    }

    /**
     * @param array<TotalResponseInterface> $totals
     * @param string $type
     * @return TotalResponseInterface|null
     */
    private function byType(array $totals, string $type): ?TotalResponseInterface
    {
        foreach ($totals as $total) {
            if ($total->getType() === $type) {
                return $total;
            }
        }

        return null;
    }

    /**
     * @param array<array{0: string, 1: string, 2: float}> $rows
     * @param float $addressSubtotal
     * @param float $addressDiscount Discount Magento stored on the address
     * @return Quote&MockObject
     */
    private function quote(array $rows, float $addressSubtotal = 0.0, float $addressDiscount = 0.0): Quote
    {
        $totals = [];

        foreach ($rows as [$code, $title, $value]) {
            $totals[] = new TotalRow($code, $title, $value);
        }

        $currency = $this->createMock(CurrencyInterface::class);
        $currency->method('getStoreCurrencyCode')->willReturn('USD');

        // getSubtotal() on a quote address resolves through __call, so it must be added.
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->addMethods(['getSubtotal', 'getDiscountAmount'])
            ->getMock();
        $address->method('getSubtotal')->willReturn($addressSubtotal);
        $address->method('getDiscountAmount')->willReturn($addressDiscount);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTotals', 'getCurrency', 'getIsVirtual', 'getShippingAddress'])
            ->addMethods(['getGrandTotal'])
            ->getMock();
        $quote->method('getTotals')->willReturn($totals);
        $quote->method('getCurrency')->willReturn($currency);
        $quote->method('getIsVirtual')->willReturn(false);
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getGrandTotal')->willReturn(42.00);

        return $quote;
    }
}
