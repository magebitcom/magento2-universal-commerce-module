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

use Magebit\UcpSpec\Api\Shopping\OrderResponseFulfillmentInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\ExpectationInterface;
use Magebit\UcpSpec\Api\Shopping\Types\ExpectationInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\ExpectationLineItemsItemInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentEventInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentEventLineItemsItemInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\PostalAddressInterface;
use Magebit\UcpSpec\Api\Shopping\Types\PostalAddressInterfaceFactory;
use Magebit\UcpSpec\Data\Shopping\OrderResponseFulfillment;
use Magebit\UcpSpec\Data\Shopping\Types\Expectation;
use Magebit\UcpSpec\Data\Shopping\Types\ExpectationLineItemsItem;
use Magebit\UcpSpec\Data\Shopping\Types\FulfillmentEvent;
use Magebit\UcpSpec\Data\Shopping\Types\FulfillmentEventLineItemsItem;
use Magebit\UcpSpec\Data\Shopping\Types\PostalAddress;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderAddressToPostalAddress;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderToFulfillment;
use Magebit\UniversalCommerce\Model\Timestamp;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Track;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\ShipmentSearchResultInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Shipping\Helper\Data as ShippingHelper;
use PHPUnit\Framework\TestCase;

class OrderToFulfillmentTest extends TestCase
{
    private const LINE_ITEM_IDS = [11 => 'line-a', 12 => 'line-b'];
    private const ORDERED = [11 => 2, 12 => 1];

    /**
     * @param Shipment[] $shipments
     * @return OrderToFulfillment
     */
    private function converterFor(array $shipments): OrderToFulfillment
    {
        $fulfillmentFactory = $this->createMock(OrderResponseFulfillmentInterfaceFactory::class);
        $fulfillmentFactory->method('create')
            ->willReturnCallback(fn (): OrderResponseFulfillment => new OrderResponseFulfillment());

        $expectationFactory = $this->createMock(ExpectationInterfaceFactory::class);
        $expectationFactory->method('create')->willReturnCallback(fn (): Expectation => new Expectation());

        $expectationLineItemFactory = $this->createMock(ExpectationLineItemsItemInterfaceFactory::class);
        $expectationLineItemFactory->method('create')
            ->willReturnCallback(fn (): ExpectationLineItemsItem => new ExpectationLineItemsItem());

        $eventFactory = $this->createMock(FulfillmentEventInterfaceFactory::class);
        $eventFactory->method('create')->willReturnCallback(fn (): FulfillmentEvent => new FulfillmentEvent());

        $eventLineItemFactory = $this->createMock(FulfillmentEventLineItemsItemInterfaceFactory::class);
        $eventLineItemFactory->method('create')
            ->willReturnCallback(fn (): FulfillmentEventLineItemsItem => new FulfillmentEventLineItemsItem());

        $postalAddressFactory = $this->createMock(PostalAddressInterfaceFactory::class);
        $postalAddressFactory->method('create')->willReturnCallback(fn (): PostalAddress => new PostalAddress());

        $shippingHelper = $this->createMock(ShippingHelper::class);
        $shippingHelper->method('getTrackingPopupUrlBySalesModel')
            ->willReturn('https://merchant.test/shipping/tracking/popup?hash=abc');

        $searchResult = $this->createMock(ShipmentSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn($shipments);

        $repository = $this->createMock(ShipmentRepositoryInterface::class);
        $repository->method('getList')->willReturn($searchResult);

        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnSelf();
        $builder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        return new OrderToFulfillment(
            $fulfillmentFactory,
            $expectationFactory,
            $expectationLineItemFactory,
            $eventFactory,
            $eventLineItemFactory,
            new OrderAddressToPostalAddress($postalAddressFactory),
            $shippingHelper,
            new Timestamp(),
            $repository,
            $builder
        );
    }

    /**
     * The expectation is what the buyer was told at checkout, so it covers everything bought regardless
     * of what has shipped.
     *
     * @return void
     */
    public function testTheExpectationCoversEveryOrderedLineItem(): void
    {
        $expectation = $this->convert()->getExpectations()[0];

        $this->assertSame(OrderToFulfillment::EXPECTATION_ID, $expectation->getId());
        $this->assertSame(['line-a', 'line-b'], array_map(
            fn ($lineItem): string => $lineItem->getId(),
            $expectation->getLineItems()
        ));
        $this->assertSame([2, 1], array_map(
            fn ($lineItem): int => $lineItem->getQuantity(),
            $expectation->getLineItems()
        ));
    }

    /**
     * @return void
     */
    public function testTheDestinationIsTheShippingAddress(): void
    {
        $destination = $this->convert()->getExpectations()[0]->getDestination();

        $this->assertInstanceOf(PostalAddressInterface::class, $destination);
        $this->assertSame('Rigas iela 1', $destination->getStreetAddress());
        $this->assertSame('Riga', $destination->getAddressLocality());
        $this->assertSame('LV', $destination->getAddressCountry());
    }

    /**
     * @return void
     */
    public function testAShippedOrderIsAShippingExpectation(): void
    {
        $this->assertSame(
            ExpectationInterface::METHOD_TYPE_SHIPPING,
            $this->convert()->getExpectations()[0]->getMethodType()
        );
    }

    /**
     * @return void
     */
    public function testAVirtualOrderIsADigitalExpectationAndFallsBackToBilling(): void
    {
        $expectation = $this->convert(isVirtual: true)->getExpectations()[0];

        $this->assertSame(ExpectationInterface::METHOD_TYPE_DIGITAL, $expectation->getMethodType());
        $this->assertSame('Brivibas iela 2', $expectation->getDestination()->getStreetAddress());
    }

    /**
     * @return void
     */
    public function testCollectingInStoreIsAPickupExpectation(): void
    {
        $expectation = $this->convert(shippingMethod: 'instore_pickup')->getExpectations()[0];

        $this->assertSame(ExpectationInterface::METHOD_TYPE_PICKUP, $expectation->getMethodType());
    }

    /**
     * An order nothing has shipped for reports the expectation and an empty log, not a missing one.
     *
     * @return void
     */
    public function testAnUnshippedOrderHasNoEvents(): void
    {
        $this->assertSame([], $this->convert()->getEvents());
    }

    /**
     * @return void
     */
    public function testAShipmentBecomesOneEvent(): void
    {
        $events = $this->convert(shipments: [$this->shipment('SHIP-1', [11 => 2.0], 'TRACK-9')])->getEvents();

        $this->assertCount(1, $events);
        $this->assertSame('SHIP-1', $events[0]->getId());
        $this->assertSame(OrderToFulfillment::EVENT_TYPE_SHIPPED, $events[0]->getType());
        $this->assertSame('2026-02-01T10:00:00+00:00', $events[0]->getOccurredAt());
        $this->assertSame('line-a', $events[0]->getLineItems()[0]->getId());
        $this->assertSame(2, $events[0]->getLineItems()[0]->getQuantity());
    }

    /**
     * @return void
     */
    public function testTrackingIsReportedWhenTheShipmentCarriesIt(): void
    {
        $events = $this->convert(shipments: [$this->shipment('SHIP-1', [11 => 1.0], 'TRACK-9')])->getEvents();

        $this->assertSame('TRACK-9', $events[0]->getTrackingNumber());
        $this->assertSame('Federal Express', $events[0]->getCarrier());
        $this->assertSame('https://merchant.test/shipping/tracking/popup?hash=abc', $events[0]->getTrackingUrl());
    }

    /**
     * Magento does not require a track, and the shipment still happened.
     *
     * @return void
     */
    public function testAnUntrackedShipmentIsStillReportedAsShipped(): void
    {
        $events = $this->convert(shipments: [$this->shipment('SHIP-2', [11 => 1.0], null)])->getEvents();

        $this->assertCount(1, $events);
        $this->assertSame(OrderToFulfillment::EVENT_TYPE_SHIPPED, $events[0]->getType());
        $this->assertNull($events[0]->getTrackingNumber());
    }

    /**
     * @param bool $isVirtual
     * @param string $shippingMethod
     * @param Shipment[] $shipments
     * @return \Magebit\UcpSpec\Api\Shopping\OrderResponseFulfillmentInterface
     */
    private function convert(
        bool $isVirtual = false,
        string $shippingMethod = 'flatrate_flatrate',
        array $shipments = []
    ): mixed {
        return $this->converterFor($shipments)->convert(
            $this->order($isVirtual, $shippingMethod),
            self::LINE_ITEM_IDS,
            self::ORDERED
        );
    }

    /**
     * @param bool $isVirtual
     * @param string $shippingMethod
     * @return Order
     */
    private function order(bool $isVirtual, string $shippingMethod): Order
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getEntityId',
                'getIsVirtual',
                'getShippingMethod',
                'getShippingAddress',
                'getBillingAddress',
                'getShippingDescription',
            ])
            ->getMock();
        $order->method('getEntityId')->willReturn(9);
        $order->method('getIsVirtual')->willReturn($isVirtual);
        $order->method('getShippingMethod')->willReturn($shippingMethod);
        $order->method('getShippingAddress')->willReturn($isVirtual ? null : $this->address('Rigas iela 1'));
        $order->method('getBillingAddress')->willReturn($this->address('Brivibas iela 2'));
        $order->method('getShippingDescription')->willReturn('Flat Rate - Fixed');

        return $order;
    }

    /**
     * @param string $street
     * @return OrderAddress
     */
    private function address(string $street): OrderAddress
    {
        $address = $this->getMockBuilder(OrderAddress::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getStreet',
                'getCity',
                'getRegion',
                'getCountryId',
                'getPostcode',
                'getFirstname',
                'getLastname',
                'getTelephone',
            ])
            ->getMock();
        $address->method('getStreet')->willReturn([$street]);
        $address->method('getCity')->willReturn('Riga');
        $address->method('getRegion')->willReturn(null);
        $address->method('getCountryId')->willReturn('LV');
        $address->method('getPostcode')->willReturn('LV-1010');
        $address->method('getFirstname')->willReturn('Anna');
        $address->method('getLastname')->willReturn('Berzina');
        $address->method('getTelephone')->willReturn('+37120000000');

        return $address;
    }

    /**
     * @param string $incrementId
     * @param array<int, float> $quantities Order item id to shipped quantity
     * @param string|null $trackNumber
     * @return Shipment
     */
    private function shipment(string $incrementId, array $quantities, ?string $trackNumber): Shipment
    {
        $items = [];

        foreach ($quantities as $orderItemId => $qty) {
            $item = $this->getMockBuilder(Shipment\Item::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getOrderItemId', 'getQty'])
                ->getMock();
            $item->method('getOrderItemId')->willReturn($orderItemId);
            $item->method('getQty')->willReturn($qty);

            $items[] = $item;
        }

        $tracks = [];

        if ($trackNumber !== null) {
            $track = $this->getMockBuilder(Track::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getTrackNumber', 'getTitle', 'getCarrierCode'])
                ->getMock();
            $track->method('getTrackNumber')->willReturn($trackNumber);
            $track->method('getTitle')->willReturn('Federal Express');
            $track->method('getCarrierCode')->willReturn('fedex');

            $tracks[] = $track;
        }

        $shipment = $this->getMockBuilder(Shipment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getIncrementId', 'getEntityId', 'getCreatedAt', 'getItems', 'getAllTracks'])
            ->getMock();
        $shipment->method('getIncrementId')->willReturn($incrementId);
        $shipment->method('getEntityId')->willReturn(1);
        $shipment->method('getCreatedAt')->willReturn('2026-02-01 10:00:00');
        $shipment->method('getItems')->willReturn($items);
        $shipment->method('getAllTracks')->willReturn($tracks);

        return $shipment;
    }
}
