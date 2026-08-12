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
use Magebit\UniversalCommerce\Exception\UcpException;
use Magebit\UniversalCommerce\Model\IdempotencyHandler;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderToOrderResponse;
use Magebit\UniversalCommerce\Model\Service\Shopping\OrderHandler;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
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
     * A missing identifier must not turn into an unfiltered search that serves an arbitrary order.
     *
     * @return void
     */
    public function testAnEmptyIdentifierNeverReachesTheRepository(): void
    {
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->expects($this->never())->method('getList');

        $handler = new OrderHandler(
            $repository,
            $this->searchCriteriaBuilder(),
            $this->createMock(OrderLinkRepositoryInterface::class),
            $this->createMock(OrderToOrderResponse::class)
        );

        $this->expectException(UcpException::class);

        $handler->getOrder('');
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
     * @param string|null $linkedCheckoutId Session the order came from, or null when it came from none
     * @param bool $found Whether an order of that increment id exists
     * @param OrderToOrderResponse|null $converter
     * @return OrderHandler
     */
    private function handler(
        ?string $linkedCheckoutId,
        bool $found = true,
        ?OrderToOrderResponse $converter = null
    ): OrderHandler {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEntityId'])
            ->getMock();
        $order->method('getEntityId')->willReturn(self::ENTITY_ID);

        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn($found ? [$order] : []);

        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->method('getList')->willReturn($searchResult);

        $links = $this->createMock(OrderLinkRepositoryInterface::class);
        $links->method('findSessionId')
            ->with(IdempotencyHandler::SCOPE, self::ENTITY_ID)
            ->willReturn($linkedCheckoutId);

        if ($converter === null) {
            $converter = $this->createMock(OrderToOrderResponse::class);
            $converter->method('convert')->willReturn($this->createMock(OrderResponseInterface::class));
        }

        return new OrderHandler($repository, $this->searchCriteriaBuilder(), $links, $converter);
    }

    /**
     * @return SearchCriteriaBuilder
     */
    private function searchCriteriaBuilder(): SearchCriteriaBuilder
    {
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnSelf();
        $builder->method('setPageSize')->willReturnSelf();
        $builder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        return $builder;
    }
}
