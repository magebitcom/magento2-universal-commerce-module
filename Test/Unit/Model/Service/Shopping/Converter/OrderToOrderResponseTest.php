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

use Magebit\UcpSpec\Api\Shopping\OrderResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\OrderLineItemInterface;
use Magebit\UcpSpec\Api\UcpResponseOrderSchemaInterfaceFactory;
use Magebit\UcpSpec\Data\Shopping\OrderResponse;
use Magebit\UcpSpec\Data\Shopping\OrderResponseFulfillment;
use Magebit\UcpSpec\Data\Shopping\Types\OrderLineItem;
use Magebit\UcpSpec\Data\UcpResponseOrderSchema;
use Magebit\UniversalCommerce\Api\ServiceInterface;
use Magebit\UniversalCommerce\Model\Config;
use Magebit\UniversalCommerce\Model\Discovery\ServiceRegistry;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderItemToOrderLineItem;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderToAdjustments;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderToFulfillment;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderToOrderResponse;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderToTotalsResponse;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

class OrderToOrderResponseTest extends TestCase
{
    /**
     * The pictures for every line are looked up once for the order, not once per line, and each line
     * gets the one belonging to its own product.
     *
     * @return void
     */
    public function testPicturesAreLookedUpOncePerOrder(): void
    {
        $passedImageUrls = [];

        $lineItemConverter = $this->createMock(OrderItemToOrderLineItem::class);
        $lineItemConverter->expects($this->once())
            ->method('imageUrls')
            ->willReturn([10 => 'https://example.com/10.jpg']);
        $lineItemConverter->expects($this->exactly(3))
            ->method('convert')
            ->willReturnCallback(
                function (
                    OrderItemInterface $orderItem,
                    string $currencyCode,
                    string $lineItemId,
                    ?string $parentId = null,
                    ?string $imageUrl = null
                ) use (&$passedImageUrls): OrderLineItemInterface {
                    $passedImageUrls[$lineItemId] = $imageUrl;

                    return new OrderLineItem();
                }
            );

        $items = [
            $this->orderItem(1, 10),
            $this->orderItem(2, 20),
            $this->orderItem(3, 10),
        ];

        $lineItems = $this->converter($lineItemConverter)->getLineItems(
            $items,
            [1 => 'line-1', 2 => 'line-2', 3 => 'line-3'],
            'USD'
        );

        $this->assertCount(3, $lineItems);
        $this->assertSame([
            'line-1' => 'https://example.com/10.jpg',
            'line-2' => null,
            'line-3' => 'https://example.com/10.jpg',
        ], $passedImageUrls);
    }

    /**
     * The order answers to the checkout session, which nobody can guess. The store's own order
     * number runs in sequence, so anyone could count up to someone else's; it is only a label.
     *
     * @return void
     */
    public function testOrderIsAddressedByCheckoutSessionAndLabelledByOrderNumber(): void
    {
        $response = $this->fullConverter()->convert($this->order(), 'session-abc');

        $this->assertSame('session-abc', $response->getId());
        $this->assertSame('session-abc', $response->getCheckoutId());
        $this->assertSame('000000123', $response->getLabel());
    }

    /**
     * @param OrderItemToOrderLineItem $lineItemConverter
     * @return OrderToOrderResponse
     */
    private function converter(OrderItemToOrderLineItem $lineItemConverter): OrderToOrderResponse
    {
        return new OrderToOrderResponse(
            $this->createMock(OrderResponseInterfaceFactory::class),
            $this->createMock(UcpResponseOrderSchemaInterfaceFactory::class),
            $lineItemConverter,
            $this->createMock(OrderToTotalsResponse::class),
            $this->createMock(OrderToFulfillment::class),
            $this->createMock(OrderToAdjustments::class),
            $this->createMock(ServiceRegistry::class),
            $this->createMock(Config::class)
        );
    }

    /**
     * Wired well enough for a whole convert() call: real response objects out of the factories, and
     * stubs for everything the identifiers do not depend on.
     *
     * @return OrderToOrderResponse
     */
    private function fullConverter(): OrderToOrderResponse
    {
        $responseFactory = $this->createMock(OrderResponseInterfaceFactory::class);
        $responseFactory->method('create')->willReturnCallback(
            static fn (): OrderResponse => new OrderResponse()
        );

        $ucpFactory = $this->createMock(UcpResponseOrderSchemaInterfaceFactory::class);
        $ucpFactory->method('create')->willReturnCallback(
            static fn (array $args = []): UcpResponseOrderSchema => new UcpResponseOrderSchema($args['data'] ?? [])
        );

        $registry = $this->createMock(ServiceRegistry::class);
        $registry->method('getService')->willReturn($this->createMock(ServiceInterface::class));

        $fulfillmentConverter = $this->createMock(OrderToFulfillment::class);
        $fulfillmentConverter->method('convert')->willReturn(new OrderResponseFulfillment());

        return new OrderToOrderResponse(
            $responseFactory,
            $ucpFactory,
            $this->createMock(OrderItemToOrderLineItem::class),
            $this->createMock(OrderToTotalsResponse::class),
            $fulfillmentConverter,
            $this->createMock(OrderToAdjustments::class),
            $registry,
            $this->createMock(Config::class)
        );
    }

    /**
     * @return Order
     */
    private function order(): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('000000123');
        $order->method('getEntityId')->willReturn(7);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getOrderCurrencyCode')->willReturn('USD');
        $order->method('getAllItems')->willReturn([]);

        return $order;
    }

    /**
     * @param int $itemId
     * @param int $productId
     * @return OrderItemInterface
     */
    private function orderItem(int $itemId, int $productId): OrderItemInterface
    {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getItemId')->willReturn($itemId);
        $item->method('getProductId')->willReturn($productId);
        $item->method('getParentItemId')->willReturn(null);

        return $item;
    }
}
