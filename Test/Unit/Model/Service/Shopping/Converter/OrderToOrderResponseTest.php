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
use Magebit\UcpSpec\Data\Shopping\Types\OrderLineItem;
use Magebit\UniversalCommerce\Model\Config;
use Magebit\UniversalCommerce\Model\Discovery\ServiceRegistry;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderItemToOrderLineItem;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderToAdjustments;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderToFulfillment;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderToOrderResponse;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderToTotalsResponse;
use Magento\Sales\Api\Data\OrderItemInterface;
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
