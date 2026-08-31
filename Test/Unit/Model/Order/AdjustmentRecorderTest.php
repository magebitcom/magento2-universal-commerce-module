<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit\Model\Order;

use Magebit\AgenticCore\Model\Order\Note;
use Magebit\UcpSpec\Api\Shopping\Types\AdjustmentInterface;
use Magebit\UcpSpec\Data\Shopping\Types\Adjustment;
use Magebit\UniversalCommerce\Api\OrderAdjustmentRepositoryInterface;
use Magebit\UniversalCommerce\Model\Order\AdjustmentRecorder;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderToAdjustments;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdjustmentRecorderTest extends TestCase
{
    private const ENTITY_ID = 42;

    private OrderAdjustmentRepositoryInterface&MockObject $repository;

    private OrderToAdjustments&MockObject $adjustmentsConverter;

    private Note&MockObject $orderNote;

    private AdjustmentRecorder $recorder;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->repository = $this->createMock(OrderAdjustmentRepositoryInterface::class);
        $this->adjustmentsConverter = $this->createMock(OrderToAdjustments::class);
        $this->orderNote = $this->createMock(Note::class);
        $this->recorder = new AdjustmentRecorder(
            $this->repository,
            $this->adjustmentsConverter,
            $this->orderNote
        );
    }

    /**
     * @return void
     */
    public function testANewAdjustmentIsRecordedAndNotedOnTheOrder(): void
    {
        $this->adjustmentsConverter->method('knownIds')->willReturn([]);
        $this->repository->expects($this->once())
            ->method('record')
            ->with(self::ENTITY_ID, 'adj_1', $this->stringContains('"type":"refund"'));
        $this->orderNote->expects($this->once())
            ->method('add')
            ->with($this->anything(), $this->stringContains('refund'));

        $recorded = $this->recorder->record($this->order(), [$this->adjustment('adj_1')]);

        $this->assertSame(1, $recorded);
    }

    /**
     * A repeated request must not add a second copy, and an agent's request for a refund is not the
     * refund: whatever the store already accounts for stands.
     *
     * @return void
     */
    public function testAnAdjustmentTheStoreAlreadyKnowsIsLeftAlone(): void
    {
        $this->adjustmentsConverter->method('knownIds')->willReturn(['adj_1']);
        $this->repository->expects($this->never())->method('record');
        $this->orderNote->expects($this->never())->method('add');

        $this->assertSame(0, $this->recorder->record($this->order(), [$this->adjustment('adj_1')]));
    }

    /**
     * @return void
     */
    public function testTheDescriptionIsCarriedIntoTheNote(): void
    {
        $this->adjustmentsConverter->method('knownIds')->willReturn([]);
        $this->orderNote->expects($this->once())
            ->method('add')
            ->with($this->anything(), $this->stringContains('Defective item'));

        $adjustment = $this->adjustment('adj_1');
        $adjustment->setDescription('Defective item');

        $this->recorder->record($this->order(), [$adjustment]);
    }

    /**
     * @return void
     */
    public function testNothingSubmittedMeansNothingRecorded(): void
    {
        $this->repository->expects($this->never())->method('record');

        $this->assertSame(0, $this->recorder->record($this->order(), []));
    }

    /**
     * @param string $id
     * @return AdjustmentInterface
     */
    private function adjustment(string $id): AdjustmentInterface
    {
        $adjustment = new Adjustment();
        $adjustment->setId($id);
        $adjustment->setType('refund');
        $adjustment->setOccurredAt('2026-08-31T12:00:00Z');
        $adjustment->setStatus(AdjustmentInterface::STATUS_PENDING);

        return $adjustment;
    }

    /**
     * @return Order&MockObject
     */
    private function order(): Order&MockObject
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEntityId'])
            ->getMock();
        $order->method('getEntityId')->willReturn(self::ENTITY_ID);

        return $order;
    }
}
