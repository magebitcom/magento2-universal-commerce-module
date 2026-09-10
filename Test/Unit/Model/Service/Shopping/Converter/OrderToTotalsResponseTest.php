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

use Magebit\AgenticCore\Model\Money\MinorUnits;
use Magebit\UcpSpec\Api\Shopping\Types\TotalResponseInterface;
use Magebit\UcpSpec\Api\Shopping\Types\TotalResponseInterfaceFactory;
use Magebit\UcpSpec\Data\Shopping\Types\TotalResponse;
use Magebit\UniversalCommerce\Api\Data\TotalTypeInterface;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderToTotalsResponse;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\TestCase;

class OrderToTotalsResponseTest extends TestCase
{
    /** @var OrderToTotalsResponse */
    private OrderToTotalsResponse $converter;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $factory = $this->createMock(TotalResponseInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(fn (): TotalResponse => new TotalResponse());

        $this->converter = new OrderToTotalsResponse($factory, new MinorUnits());
    }

    /**
     * Shipping excluding tax plus the whole tax figure is what makes the entries add up; taking shipping
     * inclusive of tax would count shipping tax twice wherever shipping is taxed.
     *
     * @return void
     */
    public function testTheEntriesSumToTheGrandTotal(): void
    {
        $totals = $this->convert([
            'subtotal' => 100.00,
            'discount_amount' => -15.00,
            'shipping_amount' => 7.50,
            'tax_amount' => 8.44,
            'grand_total' => 100.94,
        ]);

        $sum = 0;

        foreach ($totals as $type => $amount) {
            if ($type !== TotalTypeInterface::TYPE_TOTAL) {
                $sum += $amount;
            }
        }

        $this->assertSame($totals[TotalTypeInterface::TYPE_TOTAL], $sum);
    }

    /**
     * @return void
     */
    public function testTheDiscountIsNegative(): void
    {
        $totals = $this->convert([
            'subtotal' => 100.00,
            'discount_amount' => -15.00,
            'grand_total' => 85.00,
        ]);

        $this->assertSame(-1500, $totals[TotalTypeInterface::TYPE_DISCOUNT]);
    }

    /**
     * An order with no shipping charge and no tax must not report zero entries, which would read as
     * charges that applied.
     *
     * @return void
     */
    public function testEmptyChargesAreOmitted(): void
    {
        $totals = $this->convert(['subtotal' => 50.00, 'grand_total' => 50.00]);

        $this->assertSame(
            [TotalTypeInterface::TYPE_SUBTOTAL, TotalTypeInterface::TYPE_TOTAL],
            array_keys($totals)
        );
    }

    /**
     * The spec requires exactly one subtotal and one total on every breakdown.
     *
     * @return void
     */
    public function testSubtotalAndTotalAreAlwaysPresentExactlyOnce(): void
    {
        $response = $this->converter->convert($this->order([]));
        $types = array_map(fn (TotalResponseInterface $t): string => $t->getType(), $response);
        $counts = array_count_values($types);

        $this->assertSame(1, $counts[TotalTypeInterface::TYPE_SUBTOTAL]);
        $this->assertSame(1, $counts[TotalTypeInterface::TYPE_TOTAL]);
    }

    /**
     * The shipping description is what the buyer chose, so it beats a generic label.
     *
     * @return void
     */
    public function testTheShippingEntryIsLabelledWithTheChosenMethod(): void
    {
        $response = $this->converter->convert(
            $this->order(['shipping_amount' => 5.00], shippingDescription: 'Flat Rate - Fixed')
        );

        foreach ($response as $total) {
            if ($total->getType() === TotalTypeInterface::TYPE_FULFILLMENT) {
                $this->assertSame('Flat Rate - Fixed', $total->getDisplayText());

                return;
            }
        }

        $this->fail('no fulfillment total was emitted');
    }

    /**
     * @param array<string, float> $amounts
     * @return array<string, int>
     */
    private function convert(array $amounts): array
    {
        $result = [];

        foreach ($this->converter->convert($this->order($amounts)) as $total) {
            $result[$total->getType()] = $total->getAmount();
        }

        return $result;
    }

    /**
     * @param array<string, float> $amounts
     * @param string|null $shippingDescription
     * @return OrderInterface
     */
    private function order(array $amounts, ?string $shippingDescription = null): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getOrderCurrencyCode')->willReturn('USD');
        $order->method('getSubtotal')->willReturn($amounts['subtotal'] ?? 0.0);
        $order->method('getDiscountAmount')->willReturn($amounts['discount_amount'] ?? 0.0);
        $order->method('getShippingAmount')->willReturn($amounts['shipping_amount'] ?? 0.0);
        $order->method('getTaxAmount')->willReturn($amounts['tax_amount'] ?? 0.0);
        $order->method('getGrandTotal')->willReturn($amounts['grand_total'] ?? 0.0);
        $order->method('getDiscountDescription')->willReturn(null);
        $order->method('getShippingDescription')->willReturn($shippingDescription);

        return $order;
    }
}
