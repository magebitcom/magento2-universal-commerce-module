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
use Magebit\UcpSpec\Api\Shopping\Types\ItemResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\OrderLineItemInterface;
use Magebit\UcpSpec\Api\Shopping\Types\OrderLineItemInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\OrderLineItemQuantityInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\TotalResponseInterface;
use Magebit\UcpSpec\Api\Shopping\Types\TotalResponseInterfaceFactory;
use Magebit\UcpSpec\Data\Shopping\Types\ItemResponse;
use Magebit\UcpSpec\Data\Shopping\Types\OrderLineItem;
use Magebit\UcpSpec\Data\Shopping\Types\OrderLineItemQuantity;
use Magebit\UcpSpec\Data\Shopping\Types\TotalResponse;
use Magebit\UniversalCommerce\Api\Data\TotalTypeInterface;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderItemToOrderLineItem;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Sales\Api\Data\OrderItemInterface;
use PHPUnit\Framework\TestCase;

class OrderItemToOrderLineItemTest extends TestCase
{
    /** @var OrderItemToOrderLineItem */
    private OrderItemToOrderLineItem $converter;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $lineItemFactory = $this->createMock(OrderLineItemInterfaceFactory::class);
        $lineItemFactory->method('create')->willReturnCallback(fn (): OrderLineItem => new OrderLineItem());

        $quantityFactory = $this->createMock(OrderLineItemQuantityInterfaceFactory::class);
        $quantityFactory->method('create')
            ->willReturnCallback(fn (): OrderLineItemQuantity => new OrderLineItemQuantity());

        $itemFactory = $this->createMock(ItemResponseInterfaceFactory::class);
        $itemFactory->method('create')->willReturnCallback(fn (): ItemResponse => new ItemResponse());

        $totalFactory = $this->createMock(TotalResponseInterfaceFactory::class);
        $totalFactory->method('create')->willReturnCallback(fn (): TotalResponse => new TotalResponse());

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willThrowException(new \Exception('no product in this test'));

        $this->converter = new OrderItemToOrderLineItem(
            $lineItemFactory,
            $quantityFactory,
            $itemFactory,
            $totalFactory,
            $productRepository,
            $this->createMock(ImageHelper::class),
            new MinorUnits()
        );
    }

    /**
     * @return array<string, array{0: array<string, float>, 1: string}>
     */
    public static function statusProvider(): array
    {
        return [
            'nothing shipped yet' => [['qty_ordered' => 2.0], OrderLineItemInterface::STATUS_PROCESSING],
            'some shipped' => [
                ['qty_ordered' => 3.0, 'qty_shipped' => 1.0],
                OrderLineItemInterface::STATUS_PARTIAL,
            ],
            'all shipped' => [
                ['qty_ordered' => 2.0, 'qty_shipped' => 2.0],
                OrderLineItemInterface::STATUS_FULFILLED,
            ],
            'wholly canceled' => [
                ['qty_ordered' => 2.0, 'qty_canceled' => 2.0],
                OrderLineItemInterface::STATUS_REMOVED,
            ],
            'wholly refunded' => [
                ['qty_ordered' => 1.0, 'qty_shipped' => 1.0, 'qty_refunded' => 1.0],
                OrderLineItemInterface::STATUS_REMOVED,
            ],
            'shipped then partly returned' => [
                ['qty_ordered' => 3.0, 'qty_shipped' => 3.0, 'qty_refunded' => 1.0],
                OrderLineItemInterface::STATUS_FULFILLED,
            ],
        ];
    }

    /**
     * The schema dictates the derivation, so each of its four cases is pinned here rather than left to
     * whatever the quantities happen to produce.
     *
     * @dataProvider statusProvider
     * @param array<string, float> $quantities
     * @param string $expected
     * @return void
     */
    public function testStatusFollowsTheQuantities(array $quantities, string $expected): void
    {
        $this->assertSame($expected, $this->convert($quantities)->getStatus());
    }

    /**
     * A canceled quantity is gone but was still sold, so the two numbers have to differ.
     *
     * @return void
     */
    public function testOriginalKeepsWhatTheCheckoutSoldAfterACancellation(): void
    {
        $quantity = $this->convert(['qty_ordered' => 5.0, 'qty_canceled' => 2.0])->getQuantity();

        $this->assertSame(5, $quantity->getOriginal());
        $this->assertSame(3, $quantity->getTotal());
    }

    /**
     * Refunding more than was ordered would otherwise report a negative quantity, which the schema
     * forbids.
     *
     * @return void
     */
    public function testTotalNeverGoesNegative(): void
    {
        $quantity = $this->convert(['qty_ordered' => 1.0, 'qty_refunded' => 2.0])->getQuantity();

        $this->assertSame(0, $quantity->getTotal());
    }

    /**
     * Nothing ships for a virtual item, so shipped quantity would leave it forever unfulfilled.
     *
     * @return void
     */
    public function testAVirtualItemIsFulfilledByInvoicing(): void
    {
        $lineItem = $this->convert(['qty_ordered' => 1.0, 'qty_invoiced' => 1.0], isVirtual: true);

        $this->assertSame(1, $lineItem->getQuantity()->getFulfilled());
        $this->assertSame(OrderLineItemInterface::STATUS_FULFILLED, $lineItem->getStatus());
    }

    /**
     * @return void
     */
    public function testAShippableItemIsNotFulfilledByInvoicingAlone(): void
    {
        $lineItem = $this->convert(['qty_ordered' => 1.0, 'qty_invoiced' => 1.0]);

        $this->assertSame(0, $lineItem->getQuantity()->getFulfilled());
        $this->assertSame(OrderLineItemInterface::STATUS_PROCESSING, $lineItem->getStatus());
    }

    /**
     * Magento's own formula, from the tax module's item price renderer:
     * row_total - discount + tax + discount_tax_compensation.
     *
     * @return void
     */
    public function testTheLineTotalAccountsForTheDiscountAndTheTax(): void
    {
        $totals = $this->totalsOf($this->convert([
            'qty_ordered' => 2.0,
            'row_total' => 40.00,
            'discount_amount' => 5.00,
            'tax_amount' => 2.80,
            'discount_tax_compensation_amount' => 0.40,
        ]));

        $this->assertSame(4000, $totals[TotalTypeInterface::TYPE_SUBTOTAL]);
        $this->assertSame(-500, $totals[TotalTypeInterface::TYPE_ITEMS_DISCOUNT]);
        $this->assertSame(280, $totals[TotalTypeInterface::TYPE_TAX]);
        $this->assertSame(3820, $totals[TotalTypeInterface::TYPE_TOTAL]);
    }

    /**
     * @return void
     */
    public function testAChildItemPointsAtItsParent(): void
    {
        $lineItem = $this->converter->convert(
            $this->orderItem(['qty_ordered' => 1.0]),
            'USD',
            'child-1',
            'parent-1'
        );

        $this->assertSame('parent-1', $lineItem->getParentId());
    }

    /**
     * @return void
     */
    public function testATopLevelItemHasNoParent(): void
    {
        $this->assertNull($this->convert(['qty_ordered' => 1.0])->getParentId());
    }

    /**
     * @param OrderLineItemInterface $lineItem
     * @return array<string, int>
     */
    private function totalsOf(OrderLineItemInterface $lineItem): array
    {
        $result = [];

        foreach ($lineItem->getTotals() as $total) {
            /** @var TotalResponseInterface $total */
            $result[$total->getType()] = $total->getAmount();
        }

        return $result;
    }

    /**
     * @param array<string, float> $data
     * @param bool $isVirtual
     * @return OrderLineItemInterface
     */
    private function convert(array $data, bool $isVirtual = false): OrderLineItemInterface
    {
        return $this->converter->convert($this->orderItem($data, $isVirtual), 'USD', 'line-1');
    }

    /**
     * @param array<string, float> $data
     * @param bool $isVirtual
     * @return OrderItemInterface
     */
    private function orderItem(array $data, bool $isVirtual = false): OrderItemInterface
    {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getSku')->willReturn('SKU-1');
        $item->method('getName')->willReturn('Product One');
        $item->method('getPrice')->willReturn(20.00);
        $item->method('getProductId')->willReturn(null);
        $item->method('getIsVirtual')->willReturn($isVirtual ? 1 : 0);
        $item->method('getQtyOrdered')->willReturn($data['qty_ordered'] ?? 0.0);
        $item->method('getQtyShipped')->willReturn($data['qty_shipped'] ?? 0.0);
        $item->method('getQtyInvoiced')->willReturn($data['qty_invoiced'] ?? 0.0);
        $item->method('getQtyCanceled')->willReturn($data['qty_canceled'] ?? 0.0);
        $item->method('getQtyRefunded')->willReturn($data['qty_refunded'] ?? 0.0);
        $item->method('getRowTotal')->willReturn($data['row_total'] ?? 0.0);
        $item->method('getDiscountAmount')->willReturn($data['discount_amount'] ?? 0.0);
        $item->method('getTaxAmount')->willReturn($data['tax_amount'] ?? 0.0);
        $item->method('getDiscountTaxCompensationAmount')
            ->willReturn($data['discount_tax_compensation_amount'] ?? 0.0);

        return $item;
    }
}
