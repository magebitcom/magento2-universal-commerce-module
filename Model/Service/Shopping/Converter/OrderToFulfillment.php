<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\Service\Shopping\Converter;

use Magebit\UcpSpec\Api\Shopping\OrderResponseFulfillmentInterface;
use Magebit\UcpSpec\Api\Shopping\OrderResponseFulfillmentInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\ExpectationInterface;
use Magebit\UcpSpec\Api\Shopping\Types\ExpectationInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\ExpectationLineItemsItemInterface;
use Magebit\UcpSpec\Api\Shopping\Types\ExpectationLineItemsItemInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentEventInterface;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentEventInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentEventLineItemsItemInterface;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentEventLineItemsItemInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\PostalAddressInterface;
use Magebit\UniversalCommerce\Model\Timestamp;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Track;
use Magento\Shipping\Helper\Data as ShippingHelper;

/**
 * The order's fulfillment: what the buyer was told to expect, and the append-only log of what actually
 * shipped. The two are separate on purpose — an expectation can be revised, an event cannot.
 */
class OrderToFulfillment
{
    /**
     * The order is one buyer-facing group, matching the single package the checkout quoted.
     */
    public const EXPECTATION_ID = 'package';

    /**
     * `fulfillment_event.type` is an open string; this is the spec's documented value for goods handed
     * to a carrier, which is what a Magento shipment records.
     */
    public const EVENT_TYPE_SHIPPED = 'shipped';

    /**
     * Carrier codes that mean the buyer collects the goods rather than having them delivered.
     */
    private const PICKUP_CARRIERS = ['instore', 'instore_pickup', 'pickup'];

    /**
     * @param OrderResponseFulfillmentInterfaceFactory $fulfillmentFactory
     * @param ExpectationInterfaceFactory $expectationFactory
     * @param ExpectationLineItemsItemInterfaceFactory $expectationLineItemFactory
     * @param FulfillmentEventInterfaceFactory $eventFactory
     * @param FulfillmentEventLineItemsItemInterfaceFactory $eventLineItemFactory
     * @param OrderAddressToPostalAddress $addressConverter
     * @param ShippingHelper $shippingHelper
     * @param Timestamp $timestamp
     */
    public function __construct(
        private readonly OrderResponseFulfillmentInterfaceFactory $fulfillmentFactory,
        private readonly ExpectationInterfaceFactory $expectationFactory,
        private readonly ExpectationLineItemsItemInterfaceFactory $expectationLineItemFactory,
        private readonly FulfillmentEventInterfaceFactory $eventFactory,
        private readonly FulfillmentEventLineItemsItemInterfaceFactory $eventLineItemFactory,
        private readonly OrderAddressToPostalAddress $addressConverter,
        private readonly ShippingHelper $shippingHelper,
        private readonly Timestamp $timestamp
    ) {
    }

    /**
     * @param Order $order
     * @param array<int, string> $lineItemIds Order item id to the identifier the response exposes
     * @param array<int, int> $orderedQuantities Order item id to the quantity the buyer bought
     * @return OrderResponseFulfillmentInterface
     */
    public function convert(
        Order $order,
        array $lineItemIds,
        array $orderedQuantities
    ): OrderResponseFulfillmentInterface {
        /** @var OrderResponseFulfillmentInterface $fulfillment */
        $fulfillment = $this->fulfillmentFactory->create();

        $expectation = $this->convertExpectation($order, $lineItemIds, $orderedQuantities);

        if ($expectation !== null) {
            $fulfillment->setExpectations([$expectation]);
        }

        $fulfillment->setEvents($this->convertEvents($order, $lineItemIds));

        return $fulfillment;
    }

    /**
     * @param Order $order
     * @param array<int, string> $lineItemIds
     * @param array<int, int> $orderedQuantities
     * @return ExpectationInterface|null
     */
    public function convertExpectation(
        Order $order,
        array $lineItemIds,
        array $orderedQuantities
    ): ?ExpectationInterface {
        $destination = $this->destinationOf($order);
        $lineItems = [];

        foreach ($orderedQuantities as $orderItemId => $quantity) {
            if ($quantity < 1 || !isset($lineItemIds[$orderItemId])) {
                continue;
            }

            /** @var ExpectationLineItemsItemInterface $lineItem */
            $lineItem = $this->expectationLineItemFactory->create();
            $lineItem->setId($lineItemIds[$orderItemId]);
            $lineItem->setQuantity($quantity);

            $lineItems[] = $lineItem;
        }

        if ($destination === null || $lineItems === []) {
            return null;
        }

        /** @var ExpectationInterface $expectation */
        $expectation = $this->expectationFactory->create();
        $expectation->setId(self::EXPECTATION_ID);
        $expectation->setLineItems($lineItems);
        $expectation->setMethodType($this->methodTypeOf($order));
        $expectation->setDestination($destination);

        $description = $order->getShippingDescription();

        if (is_string($description) && $description !== '') {
            $expectation->setDescription($description);
        }

        return $expectation;
    }

    /**
     * Each shipment is one event. Magento allows several parcels per shipment, so an event reports the
     * first track's number and links to the shipment's tracking page, which lists all of them.
     *
     * @param Order $order
     * @param array<int, string> $lineItemIds
     * @return FulfillmentEventInterface[]
     */
    public function convertEvents(Order $order, array $lineItemIds): array
    {
        $shipments = $order->getShipmentsCollection();
        $events = [];

        if ($shipments === false) {
            return $events;
        }

        foreach ($shipments as $shipment) {
            // The collection is not typed, so nothing but the check guarantees what came out of it.
            if (!$shipment instanceof Shipment) {
                continue;
            }

            $lineItems = $this->eventLineItems($shipment, $lineItemIds);
            $occurredAt = $this->timestamp->toRfc3339($shipment->getCreatedAt());

            // An event with no time is not an event: the log is ordered by when things happened.
            if ($lineItems === [] || $occurredAt === null) {
                continue;
            }

            /** @var FulfillmentEventInterface $event */
            $event = $this->eventFactory->create();
            $event->setId($this->documentId($shipment->getIncrementId(), $shipment->getEntityId()));
            $event->setOccurredAt($occurredAt);
            // Reported as shipped even with no track recorded: the goods left, and `processing` would
            // tell the agent nothing had. The spec expects tracking on a shipped event; Magento does
            // not require it.
            $event->setType(self::EVENT_TYPE_SHIPPED);
            $event->setLineItems($lineItems);

            $this->describeTracking($event, $shipment);

            $events[] = $event;
        }

        return $events;
    }

    /**
     * A sales document is identified by its increment id; the row id stands in only for one somehow
     * saved without one.
     *
     * @param mixed $incrementId
     * @param mixed $entityId
     * @return string
     */
    private function documentId(mixed $incrementId, mixed $entityId): string
    {
        if (is_scalar($incrementId) && (string) $incrementId !== '') {
            return (string) $incrementId;
        }

        return is_numeric($entityId) ? (string) (int) $entityId : '';
    }

    /**
     * @param Shipment $shipment
     * @param array<int, string> $lineItemIds
     * @return FulfillmentEventLineItemsItemInterface[]
     */
    private function eventLineItems(Shipment $shipment, array $lineItemIds): array
    {
        $lineItems = [];

        foreach ($shipment->getAllItems() as $shipmentItem) {
            if (!$shipmentItem instanceof Shipment\Item) {
                continue;
            }

            $orderItemId = (int) $shipmentItem->getOrderItemId();
            $quantity = (int) round((float) $shipmentItem->getQty());

            if ($quantity < 1 || !isset($lineItemIds[$orderItemId])) {
                continue;
            }

            /** @var FulfillmentEventLineItemsItemInterface $lineItem */
            $lineItem = $this->eventLineItemFactory->create();
            $lineItem->setId($lineItemIds[$orderItemId]);
            $lineItem->setQuantity($quantity);

            $lineItems[] = $lineItem;
        }

        return $lineItems;
    }

    /**
     * @param FulfillmentEventInterface $event
     * @param Shipment $shipment
     * @return void
     */
    private function describeTracking(FulfillmentEventInterface $event, Shipment $shipment): void
    {
        $track = null;

        foreach ($shipment->getAllTracks() as $candidate) {
            if ($candidate instanceof Track) {
                $track = $candidate;
                break;
            }
        }

        if ($track === null) {
            return;
        }

        $trackNumber = (string) $track->getTrackNumber();

        if ($trackNumber !== '') {
            $event->setTrackingNumber($trackNumber);
        }

        $carrier = (string) ($track->getTitle() ?: $track->getCarrierCode());

        if ($carrier !== '') {
            $event->setCarrier($carrier);
        }

        // The hashed popup URL is the only tracking page Magento exposes without knowing the
        // carrier's own URL scheme, and the helper needs the concrete model to build it.
        $url = (string) $this->shippingHelper->getTrackingPopupUrlBySalesModel($shipment);

        if ($url !== '') {
            $event->setTrackingUrl($url);
        }
    }

    /**
     * @param Order $order
     * @return string
     */
    private function methodTypeOf(Order $order): string
    {
        if ($order->getIsVirtual()) {
            return ExpectationInterface::METHOD_TYPE_DIGITAL;
        }

        // getShippingMethod() can hand back a parsed object instead of the stored string.
        $shippingMethod = $order->getShippingMethod();
        $carrierCode = is_string($shippingMethod) ? explode('_', $shippingMethod)[0] : '';

        return in_array($carrierCode, self::PICKUP_CARRIERS, true)
            ? ExpectationInterface::METHOD_TYPE_PICKUP
            : ExpectationInterface::METHOD_TYPE_SHIPPING;
    }

    /**
     * A virtual order has nowhere to ship to, so the billing address stands in as the destination the
     * spec requires on every expectation.
     *
     * @param Order $order
     * @return PostalAddressInterface|null
     */
    private function destinationOf(Order $order): ?PostalAddressInterface
    {
        $address = $order->getIsVirtual() ? $order->getBillingAddress() : $order->getShippingAddress();
        $address ??= $order->getBillingAddress();

        return $address === null ? null : $this->addressConverter->convert($address);
    }
}
