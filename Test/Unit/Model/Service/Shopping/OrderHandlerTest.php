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

use Magebit\AgenticCore\Api\OrderLinkRepositoryInterface;
use Magebit\UcpSpec\Api\Shopping\OrderResponseInterface;
use Magebit\UcpSpec\Api\Shopping\OrderResponseFulfillmentInterface;
use Magebit\UcpSpec\Api\Shopping\OrderUpdateRequestFulfillmentInterface;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentEventInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterfaceFactory;
use Magebit\UcpSpec\Data\Shopping\Types\Message;
use Magebit\UcpSpec\Api\Shopping\OrderUpdateRequestInterface;
use Magebit\UcpSpec\Api\Shopping\Types\AdjustmentInterface;
use Magebit\UniversalCommerce\Exception\UcpException;
use Magebit\UniversalCommerce\Model\IdempotencyHandler;
use Magebit\UniversalCommerce\Model\Order\IncrementIdLookup;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderToOrderResponse;
use Magebit\UniversalCommerce\Model\Order\AdjustmentRecorder;
use Magebit\UniversalCommerce\Model\Service\Shopping\OrderHandler;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

class OrderHandlerTest extends TestCase
{
    private const ORDER_ID = '000000123';
    private const ENTITY_ID = 42;
    private const CHECKOUT_ID = 'checkout_session_placeholder_0001';

    /**
     * @return void
     */
    public function testAnOrderFromThisProtocolIsServed(): void
    {
        $response = $this->handler(self::CHECKOUT_ID)->getOrder(self::ORDER_ID);

        $this->assertInstanceOf(OrderResponseInterface::class, $response);
    }

    /**
     * The spec has the business verify the caller's own checkout produced the order. Until callers are
     * authenticated the link is what can be checked, so an order with no link is not this protocol's to
     * hand out — the storefront's orders included.
     *
     * @return void
     */
    public function testAnOrderPlacedOutsideThisProtocolIsNotDisclosed(): void
    {
        $this->expectException(UcpException::class);

        $this->handler(null)->getOrder(self::ORDER_ID);
    }

    /**
     * @return void
     */
    public function testAnUnknownOrderIsReportedAsNotFound(): void
    {
        try {
            $this->handler(self::CHECKOUT_ID, found: false)->getOrder(self::ORDER_ID);
        } catch (UcpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
            $this->assertSame('not_found', $exception->getErrorCode());

            return;
        }

        $this->fail('the missing order was served');
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

        $this->handler(self::CHECKOUT_ID, converter: $converter)->getOrder(self::ORDER_ID);
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

        $this->handler(self::CHECKOUT_ID, converter: $converter)->updateOrder(self::ORDER_ID, $request);
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

        $this->handler(self::CHECKOUT_ID, recorder: $recorder)
            ->updateOrder(self::ORDER_ID, $request);
    }

    /**
     * @return void
     */
    public function testAnUpdateToAnOrderFromAnotherProtocolIsRefused(): void
    {
        $recorder = $this->createMock(AdjustmentRecorder::class);
        $recorder->expects($this->never())->method('record');

        $this->expectException(UcpException::class);

        $this->handler(null, recorder: $recorder)
            ->updateOrder(self::ORDER_ID, $this->createMock(OrderUpdateRequestInterface::class));
    }

    /**
     * @param string|null $linkedCheckoutId Session the order came from, or null when it came from none
     * @param bool $found Whether an order of that increment id exists
     * @param OrderToOrderResponse|null $converter
     * @param AdjustmentRecorder|null $recorder
     * @return OrderHandler
     */
    private function handler(
        ?string $linkedCheckoutId,
        bool $found = true,
        ?OrderToOrderResponse $converter = null,
        ?AdjustmentRecorder $recorder = null
    ): OrderHandler {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEntityId'])
            ->getMock();
        $order->method('getEntityId')->willReturn(self::ENTITY_ID);

        $lookup = $this->createMock(IncrementIdLookup::class);
        $lookup->method('find')->willReturn($found ? $order : null);

        $links = $this->createMock(OrderLinkRepositoryInterface::class);
        $links->method('findSessionId')
            ->with(IdempotencyHandler::SCOPE, self::ENTITY_ID)
            ->willReturn($linkedCheckoutId);

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
            $links,
            $converter,
            $recorder ?? $this->createMock(AdjustmentRecorder::class),
            $messageFactory
        );
    }
}
