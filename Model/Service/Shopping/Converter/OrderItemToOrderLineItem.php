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

use Magebit\AgenticCore\Model\Money\MinorUnits;
use Magebit\UcpSpec\Api\Shopping\Types\ItemResponseInterface;
use Magebit\UcpSpec\Api\Shopping\Types\ItemResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\OrderLineItemInterface;
use Magebit\UcpSpec\Api\Shopping\Types\OrderLineItemInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\OrderLineItemQuantityInterface;
use Magebit\UcpSpec\Api\Shopping\Types\OrderLineItemQuantityInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\TotalResponseInterface;
use Magebit\UcpSpec\Api\Shopping\Types\TotalResponseInterfaceFactory;
use Magebit\UniversalCommerce\Api\Data\TotalTypeInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * Maps one order item onto an order line item, including the quantity tracking the checkout's line
 * items have no equivalent of.
 */
class OrderItemToOrderLineItem
{
    /**
     * @param OrderLineItemInterfaceFactory $lineItemFactory
     * @param OrderLineItemQuantityInterfaceFactory $quantityFactory
     * @param ItemResponseInterfaceFactory $itemFactory
     * @param TotalResponseInterfaceFactory $totalFactory
     * @param ProductRepositoryInterface $productRepository
     * @param ImageHelper $imageHelper
     * @param MinorUnits $minorUnits
     */
    public function __construct(
        private readonly OrderLineItemInterfaceFactory $lineItemFactory,
        private readonly OrderLineItemQuantityInterfaceFactory $quantityFactory,
        private readonly ItemResponseInterfaceFactory $itemFactory,
        private readonly TotalResponseInterfaceFactory $totalFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ImageHelper $imageHelper,
        private readonly MinorUnits $minorUnits
    ) {
    }

    /**
     * @param OrderItemInterface $orderItem
     * @param string $currencyCode
     * @param string $lineItemId Identifier the response exposes for this item
     * @param string|null $parentId Identifier of the enclosing line item, for nested products
     * @return OrderLineItemInterface
     */
    public function convert(
        OrderItemInterface $orderItem,
        string $currencyCode,
        string $lineItemId,
        ?string $parentId = null
    ): OrderLineItemInterface {
        $quantity = $this->convertQuantity($orderItem);

        /** @var OrderLineItemInterface $lineItem */
        $lineItem = $this->lineItemFactory->create();
        $lineItem->setId($lineItemId);
        $lineItem->setItem($this->convertItem($orderItem, $currencyCode));
        $lineItem->setQuantity($quantity);
        $lineItem->setTotals($this->convertTotals($orderItem, $currencyCode));
        $lineItem->setStatus($this->deriveStatus($quantity));

        if ($parentId !== null) {
            $lineItem->setParentId($parentId);
        }

        return $lineItem;
    }

    /**
     * `total` is what remains after cancellations and returns, so an agent reading only it sees the
     * order as it stands; `original` preserves what the checkout sold.
     *
     * The spec types all three as integers, so a fractional quantity is reported rounded — the same
     * limitation the checkout's line items have.
     *
     * @param OrderItemInterface $orderItem
     * @return OrderLineItemQuantityInterface
     */
    public function convertQuantity(OrderItemInterface $orderItem): OrderLineItemQuantityInterface
    {
        $ordered = (float) $orderItem->getQtyOrdered();
        $removed = (float) $orderItem->getQtyCanceled() + (float) $orderItem->getQtyRefunded();
        $total = max(0.0, $ordered - $removed);

        // Capped at what is still active: Magento keeps the shipped quantity after a return, and
        // reporting more fulfilled than remains would read as a quantity that is both gone and in hand.
        $fulfilled = min($total, $this->fulfilledQty($orderItem));

        /** @var OrderLineItemQuantityInterface $quantity */
        $quantity = $this->quantityFactory->create();
        $quantity->setOriginal((int) round($ordered));
        $quantity->setTotal((int) round($total));
        $quantity->setFulfilled((int) round($fulfilled));

        return $quantity;
    }

    /**
     * Nothing ships for a virtual item, so its shipped quantity stays at zero forever and invoicing is
     * the only signal that the buyer received it.
     *
     * @param OrderItemInterface $orderItem
     * @return float
     */
    private function fulfilledQty(OrderItemInterface $orderItem): float
    {
        if ((bool) $orderItem->getIsVirtual()) {
            return (float) $orderItem->getQtyInvoiced();
        }

        return (float) $orderItem->getQtyShipped();
    }

    /**
     * The derivation is dictated by the schema, which spells out the four cases in prose.
     *
     * @param OrderLineItemQuantityInterface $quantity
     * @return string
     */
    public function deriveStatus(OrderLineItemQuantityInterface $quantity): string
    {
        $total = $quantity->getTotal();
        $fulfilled = $quantity->getFulfilled();

        if ($total === 0) {
            return OrderLineItemInterface::STATUS_REMOVED;
        }

        if ($fulfilled >= $total) {
            return OrderLineItemInterface::STATUS_FULFILLED;
        }

        return $fulfilled > 0 ? OrderLineItemInterface::STATUS_PARTIAL : OrderLineItemInterface::STATUS_PROCESSING;
    }

    /**
     * @param OrderItemInterface $orderItem
     * @param string $currencyCode
     * @return ItemResponseInterface
     */
    public function convertItem(OrderItemInterface $orderItem, string $currencyCode): ItemResponseInterface
    {
        /** @var ItemResponseInterface $item */
        $item = $this->itemFactory->create();
        $item->setId((string) $orderItem->getSku());
        $item->setTitle((string) $orderItem->getName());
        $item->setPrice($this->minorUnits->convert((float) $orderItem->getPrice(), $currencyCode));

        $imageUrl = $this->getProductImageUrl($orderItem);

        if ($imageUrl !== null) {
            $item->setImageUrl($imageUrl);
        }

        return $item;
    }

    /**
     * Magento's own formula for an item's total after discount and tax, taken from the tax module's
     * item price renderer so the order agrees with what the storefront shows.
     *
     * @param OrderItemInterface $orderItem
     * @param string $currencyCode
     * @return TotalResponseInterface[]
     */
    public function convertTotals(OrderItemInterface $orderItem, string $currencyCode): array
    {
        $rowTotal = (float) $orderItem->getRowTotal();
        $discount = abs((float) $orderItem->getDiscountAmount());
        $tax = (float) $orderItem->getTaxAmount();
        $taxCompensation = (float) $orderItem->getDiscountTaxCompensationAmount();

        $totals = [$this->total(TotalTypeInterface::TYPE_SUBTOTAL, 'Subtotal', $rowTotal, $currencyCode)];

        if ($discount > 0) {
            $totals[] = $this->total(
                TotalTypeInterface::TYPE_ITEMS_DISCOUNT,
                'Discount',
                -$discount,
                $currencyCode
            );
        }

        if ($tax > 0) {
            $totals[] = $this->total(TotalTypeInterface::TYPE_TAX, 'Tax', $tax, $currencyCode);
        }

        $totals[] = $this->total(
            TotalTypeInterface::TYPE_TOTAL,
            'Total',
            $rowTotal - $discount + $tax + $taxCompensation,
            $currencyCode
        );

        return $totals;
    }

    /**
     * @param string $type
     * @param string $displayText
     * @param float $amount
     * @param string $currencyCode
     * @return TotalResponseInterface
     */
    private function total(
        string $type,
        string $displayText,
        float $amount,
        string $currencyCode
    ): TotalResponseInterface {
        /** @var TotalResponseInterface $total */
        $total = $this->totalFactory->create();
        $total->setType($type);
        $total->setDisplayText($displayText);
        $total->setAmount($this->minorUnits->convert($amount, $currencyCode));

        return $total;
    }

    /**
     * A product deleted since the order was placed is normal, and costs the agent only the thumbnail.
     *
     * @param OrderItemInterface $orderItem
     * @return string|null
     */
    private function getProductImageUrl(OrderItemInterface $orderItem): ?string
    {
        $productId = $orderItem->getProductId();

        if ($productId === null) {
            return null;
        }

        try {
            $product = $this->productRepository->getById((int) $productId, false, (int) $orderItem->getStoreId());

            if (!$product instanceof Product) {
                return null;
            }

            return $this->imageHelper->init($product, 'product_base_image')->getUrl() ?: null;
        } catch (\Exception $exception) {
            return null;
        }
    }
}
