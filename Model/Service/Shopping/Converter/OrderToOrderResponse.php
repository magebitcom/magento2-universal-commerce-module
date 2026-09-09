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

use Magebit\UcpSpec\Api\Shopping\OrderResponseInterface;
use Magebit\UcpSpec\Api\Shopping\OrderResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\OrderLineItemInterface;
use Magebit\UcpSpec\Api\UcpResponseOrderSchemaInterface;
use Magebit\UcpSpec\Api\UcpResponseOrderSchemaInterfaceFactory;
use Magebit\UniversalCommerce\Api\UniversalCommerceProtocolInterface;
use Magebit\UniversalCommerce\Model\Config;
use Magebit\UniversalCommerce\Model\Discovery\ServiceRegistry;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order;

/**
 * Builds the order representation the Order capability serves and the order webhooks carry.
 */
class OrderToOrderResponse
{
    /**
     * Path the storefront serves an order on, which is what `permalink_url` points at.
     */
    private const PERMALINK_PATH = '/sales/order/view/order_id/';

    /**
     * @param OrderResponseInterfaceFactory $orderResponseFactory
     * @param UcpResponseOrderSchemaInterfaceFactory $ucpResponseFactory
     * @param OrderItemToOrderLineItem $lineItemConverter
     * @param OrderToTotalsResponse $totalsConverter
     * @param OrderToFulfillment $fulfillmentConverter
     * @param OrderToAdjustments $adjustmentsConverter
     * @param ServiceRegistry $serviceRegistry
     * @param Config $config
     */
    public function __construct(
        private readonly OrderResponseInterfaceFactory $orderResponseFactory,
        private readonly UcpResponseOrderSchemaInterfaceFactory $ucpResponseFactory,
        private readonly OrderItemToOrderLineItem $lineItemConverter,
        private readonly OrderToTotalsResponse $totalsConverter,
        private readonly OrderToFulfillment $fulfillmentConverter,
        private readonly OrderToAdjustments $adjustmentsConverter,
        private readonly ServiceRegistry $serviceRegistry,
        private readonly Config $config
    ) {
    }

    /**
     * @param Order $order
     * @param string $checkoutId Session the order was placed from
     * @return OrderResponseInterface
     * @throws LocalizedException When the shopping service is not registered
     */
    public function convert(Order $order, string $checkoutId): OrderResponseInterface
    {
        $currencyCode = (string) $order->getOrderCurrencyCode();
        $items = $this->itemsOf($order);
        $lineItemIds = $this->lineItemIds($items);

        /** @var OrderResponseInterface $response */
        $response = $this->orderResponseFactory->create();
        $response->setUcp($this->getUcp());
        // The order is addressed by its checkout session, which nobody can guess. The store's own
        // order number is only a label, because it runs in sequence and anyone could count up to it.
        $response->setId($checkoutId);
        $response->setLabel((string) $order->getIncrementId());
        $response->setCheckoutId($checkoutId);
        $response->setPermalinkUrl($this->permalinkUrl($order));
        $response->setLineItems($this->getLineItems($items, $lineItemIds, $currencyCode));
        $response->setFulfillment(
            $this->fulfillmentConverter->convert($order, $lineItemIds, $this->orderedQuantities($items))
        );
        $response->setCurrency($currencyCode);
        $response->setTotals($this->totalsConverter->convert($order));

        $adjustments = $this->adjustmentsConverter->convert($order, $lineItemIds);

        if ($adjustments !== []) {
            $response->setAdjustments($adjustments);
        }

        return $response;
    }

    /**
     * @return UcpResponseOrderSchemaInterface
     * @throws LocalizedException
     */
    public function getUcp(): UcpResponseOrderSchemaInterface
    {
        $service = $this->serviceRegistry->getService('dev.ucp.shopping');

        if (!$service) {
            throw new LocalizedException(__('Shopping service not registered'));
        }

        // No payment handlers: the order is already paid for, and the schema says as much.
        return $this->ucpResponseFactory->create([
            'data' => [
                UcpResponseOrderSchemaInterface::KEY_VERSION => UniversalCommerceProtocolInterface::SPEC_VERSION,
                UcpResponseOrderSchemaInterface::KEY_STATUS => UcpResponseOrderSchemaInterface::STATUS_SUCCESS,
                UcpResponseOrderSchemaInterface::KEY_CAPABILITIES => $service->getCapabilities(),
            ]
        ]);
    }

    /**
     * @param OrderItemInterface[] $items
     * @param array<int, string> $lineItemIds
     * @param string $currencyCode
     * @return OrderLineItemInterface[]
     */
    public function getLineItems(array $items, array $lineItemIds, string $currencyCode): array
    {
        $lineItems = [];

        foreach ($items as $item) {
            $itemId = (int) $item->getItemId();
            $parentItemId = $item->getParentItemId();
            $parentId = $parentItemId === null ? null : ($lineItemIds[(int) $parentItemId] ?? null);

            $lineItems[] = $this->lineItemConverter->convert(
                $item,
                $currencyCode,
                $lineItemIds[$itemId] ?? (string) $itemId,
                $parentId
            );
        }

        return $lineItems;
    }

    /**
     * The line item keeps the identifier the checkout already gave the agent, so the two responses can
     * be reconciled against each other; an item added after the order has no such identifier and falls
     * back to its own.
     *
     * @param OrderItemInterface[] $items
     * @return array<int, string> Order item id to the identifier the response exposes
     */
    public function lineItemIds(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            $itemId = (int) $item->getItemId();
            $quoteItemId = $item->getQuoteItemId();

            $ids[$itemId] = $quoteItemId === null ? (string) $itemId : (string) $quoteItemId;
        }

        return $ids;
    }

    /**
     * @param OrderItemInterface[] $items
     * @return array<int, int> Order item id to the quantity the buyer bought
     */
    private function orderedQuantities(array $items): array
    {
        $quantities = [];

        foreach ($items as $item) {
            $quantities[(int) $item->getItemId()] = (int) round((float) $item->getQtyOrdered());
        }

        return $quantities;
    }

    /**
     * Includes the children of composite products, which carry `parent_id` — the same set the checkout
     * response lists, so line items do not appear or vanish when the order is placed.
     *
     * @param Order $order
     * @return OrderItemInterface[]
     */
    private function itemsOf(Order $order): array
    {
        return array_values($order->getAllItems());
    }

    /**
     * @param Order $order
     * @return string
     */
    private function permalinkUrl(Order $order): string
    {
        $entityId = $order->getEntityId();

        return $this->config->getApiBaseUrl((int) $order->getStoreId())
            . self::PERMALINK_PATH . (is_numeric($entityId) ? (string) (int) $entityId : '');
    }
}
