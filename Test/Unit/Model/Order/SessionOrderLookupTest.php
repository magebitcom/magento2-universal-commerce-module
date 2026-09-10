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

use Magebit\AgenticCore\Api\OrderLinkRepositoryInterface;
use Magebit\UniversalCommerce\Model\IdempotencyHandler;
use Magebit\UniversalCommerce\Model\Order\SessionOrderLookup;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

class SessionOrderLookupTest extends TestCase
{
    private const CHECKOUT_ID = 'checkout_session_placeholder_0001';
    private const ORDER_ID = 42;

    /**
     * @return void
     */
    public function testTheOrderTheSessionPlacedIsFound(): void
    {
        $order = $this->order();

        $this->assertSame($order, $this->lookup(self::ORDER_ID, $order)->find(self::CHECKOUT_ID));
    }

    /**
     * @return void
     */
    public function testASessionThatPlacedNoOrderFindsNothing(): void
    {
        $this->assertNull($this->lookup(null, $this->order())->find(self::CHECKOUT_ID));
    }

    /**
     * @return void
     */
    public function testAnOrderThatIsGoneFindsNothing(): void
    {
        $this->assertNull($this->lookup(self::ORDER_ID, null)->find(self::CHECKOUT_ID));
    }

    /**
     * An empty path segment is not an identifier, so nothing is looked up for it.
     *
     * @return void
     */
    public function testAnEmptyIdentifierIsNotLookedUp(): void
    {
        $links = $this->createMock(OrderLinkRepositoryInterface::class);
        $links->expects($this->never())->method('findOrderId');

        $lookup = new SessionOrderLookup($links, $this->createMock(OrderRepositoryInterface::class));

        $this->assertNull($lookup->find(''));
    }

    /**
     * @return void
     */
    public function testOnlyThisProtocolsLinksAreRead(): void
    {
        $links = $this->createMock(OrderLinkRepositoryInterface::class);
        $links->expects($this->once())
            ->method('findOrderId')
            ->with(IdempotencyHandler::SCOPE, self::CHECKOUT_ID)
            ->willReturn(null);

        $lookup = new SessionOrderLookup($links, $this->createMock(OrderRepositoryInterface::class));

        $this->assertNull($lookup->find(self::CHECKOUT_ID));
    }

    /**
     * @param int|null $linkedOrderId Order the session placed, or null when it placed none
     * @param Order|null $order The order the repository still holds, or null when it is gone
     * @return SessionOrderLookup
     */
    private function lookup(?int $linkedOrderId, ?Order $order): SessionOrderLookup
    {
        $links = $this->createMock(OrderLinkRepositoryInterface::class);
        $links->method('findOrderId')->willReturn($linkedOrderId);

        $orders = $this->createMock(OrderRepositoryInterface::class);

        if ($order === null) {
            $orders->method('get')->willThrowException(new NoSuchEntityException(__('No such order.')));
        } else {
            $orders->method('get')->willReturn($order);
        }

        return new SessionOrderLookup($links, $orders);
    }

    /**
     * @return Order
     */
    private function order(): Order
    {
        return $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->getMock();
    }
}
