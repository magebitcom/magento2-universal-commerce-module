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
use Magebit\UcpSpec\Api\Shopping\Types\AdjustmentInterface;
use Magebit\UcpSpec\Api\Shopping\Types\AdjustmentInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\AdjustmentLineItemsItemInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\TotalResponseInterfaceFactory;
use Magebit\UcpSpec\Data\Shopping\Types\Adjustment;
use Magebit\UcpSpec\Data\Shopping\Types\AdjustmentLineItemsItem;
use Magebit\UcpSpec\Data\Shopping\Types\TotalResponse;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderToAdjustments;
use Magebit\UniversalCommerce\Model\Timestamp;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use PHPUnit\Framework\TestCase;

class OrderToAdjustmentsTest extends TestCase
{
    private const LINE_ITEM_IDS = [11 => 'line-a', 12 => 'line-b'];

    /** @var OrderToAdjustments */
    private OrderToAdjustments $converter;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $adjustmentFactory = $this->createMock(AdjustmentInterfaceFactory::class);
        $adjustmentFactory->method('create')->willReturnCallback(fn (): Adjustment => new Adjustment());

        $lineItemFactory = $this->createMock(AdjustmentLineItemsItemInterfaceFactory::class);
        $lineItemFactory->method('create')
            ->willReturnCallback(fn (): AdjustmentLineItemsItem => new AdjustmentLineItemsItem());

        $totalFactory = $this->createMock(TotalResponseInterfaceFactory::class);
        $totalFactory->method('create')->willReturnCallback(fn (): TotalResponse => new TotalResponse());

        $this->converter = new OrderToAdjustments(
            $adjustmentFactory,
            $lineItemFactory,
            $totalFactory,
            new MinorUnits(),
            new Timestamp()
        );
    }

    /**
     * @return void
     */
    public function testAnUntouchedOrderHasNoAdjustments(): void
    {
        $this->assertSame([], $this->convert());
    }

    /**
     * @return void
     */
    public function testACreditMemoBecomesARefund(): void
    {
        $adjustments = $this->convert(creditmemos: [$this->creditmemo('CM-1', 25.00, [11 => 1.0])]);

        $this->assertCount(1, $adjustments);
        $this->assertSame('CM-1', $adjustments[0]->getId());
        $this->assertSame(OrderToAdjustments::TYPE_REFUND, $adjustments[0]->getType());
        $this->assertSame(AdjustmentInterface::STATUS_COMPLETED, $adjustments[0]->getStatus());
        $this->assertSame('2026-03-04T09:30:00+00:00', $adjustments[0]->getOccurredAt());
    }

    /**
     * The sign is direction: this money went back to the buyer.
     *
     * @return void
     */
    public function testARefundedAmountIsNegative(): void
    {
        $adjustments = $this->convert(creditmemos: [$this->creditmemo('CM-1', 25.00, [11 => 1.0])]);

        $this->assertSame(-2500, $adjustments[0]->getTotals()[0]->getAmount());
    }

    /**
     * The schema reads a negative quantity as a reduction, which is what a return is.
     *
     * @return void
     */
    public function testARefundedQuantityIsNegative(): void
    {
        $adjustments = $this->convert(creditmemos: [$this->creditmemo('CM-1', 25.00, [11 => 2.0])]);

        $this->assertSame('line-a', $adjustments[0]->getLineItems()[0]->getId());
        $this->assertSame(-2, $adjustments[0]->getLineItems()[0]->getQuantity());
    }

    /**
     * An adjustment can exist without touching line items — an adjustment-fee-only refund, say — so an
     * unmatched credit memo item must not invent one.
     *
     * @return void
     */
    public function testACreditMemoForNoKnownItemStillReportsTheMoney(): void
    {
        $adjustments = $this->convert(creditmemos: [$this->creditmemo('CM-2', 5.00, [99 => 1.0])]);

        $this->assertCount(1, $adjustments);
        $this->assertNull($adjustments[0]->getLineItems());
        $this->assertSame(-500, $adjustments[0]->getTotals()[0]->getAmount());
    }

    /**
     * @return void
     */
    public function testACanceledOrderReportsACancellation(): void
    {
        $adjustments = $this->convert(isCanceled: true);

        $this->assertCount(1, $adjustments);
        $this->assertSame(OrderToAdjustments::TYPE_CANCELLATION, $adjustments[0]->getType());
        $this->assertSame('cancellation-000000123', $adjustments[0]->getId());
    }

    /**
     * @return void
     */
    public function testRefundsAndACancellationAreBothReported(): void
    {
        $adjustments = $this->convert(
            creditmemos: [$this->creditmemo('CM-1', 25.00, [11 => 1.0])],
            isCanceled: true
        );

        $this->assertSame(
            [OrderToAdjustments::TYPE_REFUND, OrderToAdjustments::TYPE_CANCELLATION],
            array_map(fn (AdjustmentInterface $a): string => $a->getType(), $adjustments)
        );
    }

    /**
     * @param Creditmemo[] $creditmemos
     * @param bool $isCanceled
     * @return AdjustmentInterface[]
     */
    private function convert(array $creditmemos = [], bool $isCanceled = false): array
    {
        return $this->converter->convert($this->order($creditmemos, $isCanceled), self::LINE_ITEM_IDS);
    }

    /**
     * @param Creditmemo[] $creditmemos
     * @param bool $isCanceled
     * @return Order
     */
    private function order(array $creditmemos, bool $isCanceled): Order
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getCreditmemosCollection',
                'isCanceled',
                'getOrderCurrencyCode',
                'getIncrementId',
                'getUpdatedAt',
            ])
            ->getMock();
        $order->method('getCreditmemosCollection')->willReturn($creditmemos === [] ? false : $creditmemos);
        $order->method('isCanceled')->willReturn($isCanceled);
        $order->method('getOrderCurrencyCode')->willReturn('USD');
        $order->method('getIncrementId')->willReturn('000000123');
        $order->method('getUpdatedAt')->willReturn('2026-03-05 12:00:00');

        return $order;
    }

    /**
     * @param string $incrementId
     * @param float $grandTotal
     * @param array<int, float> $quantities Order item id to refunded quantity
     * @return Creditmemo
     */
    private function creditmemo(string $incrementId, float $grandTotal, array $quantities): Creditmemo
    {
        $items = [];

        foreach ($quantities as $orderItemId => $qty) {
            $item = $this->getMockBuilder(Creditmemo\Item::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getOrderItemId', 'getQty'])
                ->getMock();
            $item->method('getOrderItemId')->willReturn($orderItemId);
            $item->method('getQty')->willReturn($qty);

            $items[] = $item;
        }

        $creditmemo = $this->getMockBuilder(Creditmemo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getIncrementId', 'getEntityId', 'getCreatedAt', 'getGrandTotal', 'getAllItems'])
            ->getMock();
        $creditmemo->method('getIncrementId')->willReturn($incrementId);
        $creditmemo->method('getEntityId')->willReturn(7);
        $creditmemo->method('getCreatedAt')->willReturn('2026-03-04 09:30:00');
        $creditmemo->method('getGrandTotal')->willReturn($grandTotal);
        $creditmemo->method('getAllItems')->willReturn($items);

        return $creditmemo;
    }
}
