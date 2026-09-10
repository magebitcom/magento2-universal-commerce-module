<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit\Model\Service\Shopping;

use Magebit\UcpSpec\Api\Shopping\OrderResponseInterface;
use Magebit\UcpSpec\Api\Shopping\OrderResponseFulfillmentInterface;
use Magebit\UcpSpec\Api\Shopping\OrderUpdateRequestFulfillmentInterface;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentEventInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterfaceFactory;
use Magebit\UcpSpec\Data\Shopping\Types\Message;
use Magebit\UcpSpec\Api\Shopping\OrderUpdateRequestInterface;
use Magebit\UcpSpec\Api\Shopping\Types\AdjustmentInterface;
use Magebit\UniversalCommerce\Exception\UcpException;
use Magebit\UniversalCommerce\Model\Order\AdjustmentRecorder;
use Magebit\UniversalCommerce\Model\Order\SessionOrderLookup;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderToOrderResponse;
use Magebit\UniversalCommerce\Model\Service\Shopping\OrderHandler;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

class OrderHandlerTest extends TestCase
{
    private const INCREMENT_ID = '000000123';
    private const ENTITY_ID = 42;
    private const CHECKOUT_ID = 'checkout_session_placeholder_0001';

    /**
     * @return void
     */
    public function testAnOrderFromThisProtocolIsServed(): void
    {
        $response = $this->handler(true)->getOrder(self::CHECKOUT_ID);

        $this->assertInstanceOf(OrderResponseInterface::class, $response);
    }

    /**
     * The store's order number runs in sequence, so anyone could count up to someone else's order. It
     * addresses nothing here, and a caller holding one is told the same as a caller holding nothing.
     *
     * @return void
     */
    public function testAnOrderNumberDoesNotReachAnOrder(): void
    {
        try {
            $this->handler(true)->getOrder(self::INCREMENT_ID);
        } catch (UcpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
            $this->assertSame('not_found', $exception->getErrorCode());

            return;
        }

        $this->fail('an order number reached an order');
    }

    /**
     * Orders placed through the storefront, or through the other protocol, have no session of this
     * protocol behind them, so this protocol does not hand them out.
     *
     * @return void
     */
    public function testAnOrderPlacedOutsideThisProtocolIsNotDisclosed(): void
    {
        try {
            $this->handler(false)->getOrder(self::CHECKOUT_ID);
        } catch (UcpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
            $this->assertSame('not_found', $exception->getErrorCode());

            return;
        }

        $this->fail('an unlinked order was served');
    }

    /**
     * @return void
     */
    public function testTheResponseIsBuiltForTheLinkedCheckout(): void
    {
        $converter = $this->createMock(OrderToOrderResponse::class);
        $converter->expects($this->once())
            ->method('convert')
            ->with($this->isInstanceOf(Order::class), self::CHECKOUT_ID)
            ->willReturn($this->createMock(OrderResponseInterface::class));

        $this->handler(true, converter: $converter)->getOrder(self::CHECKOUT_ID);
    }

    /**
     * The shipment log says what the store did, so an agent cannot write into it — but it is told so,
     * rather than left to assume its event landed.
     *
     * @return void
     */
    public function testASubmittedShipmentEventIsRefusedInWriting(): void
    {
        $recorded = $this->createMock(OrderResponseInterface::class);
        $recorded->method('getFulfillment')->willReturn($this->fulfillment(0));
        $recorded->expects($this->once())
            ->method('setMessages')
            ->with($this->callback(static function (array $messages): bool {
                return count($messages) === 1
                    && $messages[0]->getType() === 'warning'
                    && $messages[0]->getPath() === '$.fulfillment.events';
            }));

        $this->updateWith($recorded, submittedEvents: 1);
    }

    /**
     * @return void
     */
    public function testAnUpdateThatAddsNoShipmentEventIsNotWarnedAbout(): void
    {
        $recorded = $this->createMock(OrderResponseInterface::class);
        $recorded->method('getFulfillment')->willReturn($this->fulfillment(0));
        $recorded->expects($this->never())->method('setMessages');

        $this->updateWith($recorded, submittedEvents: 0);
    }

    /**
     * @param OrderResponseInterface $recorded Order the converter reports
     * @param int $submittedEvents How many shipment events the request carries
     * @return void
     */
    private function updateWith(OrderResponseInterface $recorded, int $submittedEvents): void
    {
        $converter = $this->createMock(OrderToOrderResponse::class);
        $converter->method('convert')->willReturn($recorded);

        $requestFulfillment = $this->createMock(OrderUpdateRequestFulfillmentInterface::class);
        $requestFulfillment->method('getEvents')->willReturn($this->events($submittedEvents));

        $request = $this->createMock(OrderUpdateRequestInterface::class);
        $request->method('getFulfillment')->willReturn($requestFulfillment);

        $this->handler(true, converter: $converter)->updateOrder(self::CHECKOUT_ID, $request);
    }

    /**
     * @param int $count
     * @return OrderResponseFulfillmentInterface
     */
    private function fulfillment(int $count): OrderResponseFulfillmentInterface
    {
        $fulfillment = $this->createMock(OrderResponseFulfillmentInterface::class);
        $fulfillment->method('getEvents')->willReturn($this->events($count));

        return $fulfillment;
    }

    /**
     * @param int $count
     * @return FulfillmentEventInterface[]
     */
    private function events(int $count): array
    {
        $events = [];

        for ($index = 0; $index < $count; $index++) {
            $events[] = $this->createMock(FulfillmentEventInterface::class);
        }

        return $events;
    }

    /**
     * @return void
     */
    public function testAnUpdateRecordsTheAdjustmentsItCarries(): void
    {
        $adjustment = $this->createMock(AdjustmentInterface::class);
        $recorder = $this->createMock(AdjustmentRecorder::class);
        $recorder->expects($this->once())
            ->method('record')
            ->with($this->isInstanceOf(Order::class), [$adjustment]);

        $request = $this->createMock(OrderUpdateRequestInterface::class);
        $request->method('getAdjustments')->willReturn([$adjustment]);

        $this->handler(true, recorder: $recorder)
            ->updateOrder(self::CHECKOUT_ID, $request);
    }

    /**
     * @return void
     */
    public function testAnUpdateToAnOrderFromAnotherProtocolIsRefused(): void
    {
        $recorder = $this->createMock(AdjustmentRecorder::class);
        $recorder->expects($this->never())->method('record');

        $this->expectException(UcpException::class);

        $this->handler(false, recorder: $recorder)
            ->updateOrder(self::CHECKOUT_ID, $this->createMock(OrderUpdateRequestInterface::class));
    }

    /**
     * @param bool $linked Whether the checkout session placed an order this protocol may hand out
     * @param OrderToOrderResponse|null $converter
     * @param AdjustmentRecorder|null $recorder
     * @return OrderHandler
     */
    private function handler(
        bool $linked,
        ?OrderToOrderResponse $converter = null,
        ?AdjustmentRecorder $recorder = null
    ): OrderHandler {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEntityId'])
            ->getMock();
        $order->method('getEntityId')->willReturn(self::ENTITY_ID);

        $lookup = $this->createMock(SessionOrderLookup::class);
        // Only the session that placed the order finds it; every other identifier finds nothing.
        $lookup->method('find')->willReturnCallback(
            static fn (string $identifier): ?Order =>
                $linked && $identifier === self::CHECKOUT_ID ? $order : null
        );

        if ($converter === null) {
            $converter = $this->createMock(OrderToOrderResponse::class);
            $converter->method('convert')->willReturn($this->createMock(OrderResponseInterface::class));
        }

        $messageFactory = $this->createMock(MessageInterfaceFactory::class);
        $messageFactory->method('create')->willReturnCallback(
            static fn (array $arguments = []): Message => new Message($arguments['data'] ?? [])
        );

        return new OrderHandler(
            $lookup,
            $converter,
            $recorder ?? $this->createMock(AdjustmentRecorder::class),
            $messageFactory
        );
    }
}
