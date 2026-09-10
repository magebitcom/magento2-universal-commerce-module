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
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * Maps one order item onto an order line item, including the quantity tracking the checkout's line
 * items have no equivalent of.
 */
class OrderItemToOrderLineItem
{
    /**
     * Image the thumbnail is taken from, the same one the storefront shows on a product page.
     */
    private const IMAGE_ID = 'product_base_image';

    /**
     * Tells Magento the stock filter is already handled, so an out-of-stock product keeps its picture.
     */
    private const STOCK_FILTER_FLAG = 'has_stock_status_filter';

    /**
     * @param OrderLineItemInterfaceFactory $lineItemFactory
     * @param OrderLineItemQuantityInterfaceFactory $quantityFactory
     * @param ItemResponseInterfaceFactory $itemFactory
     * @param TotalResponseInterfaceFactory $totalFactory
     * @param CollectionFactory $productCollectionFactory
     * @param ImageHelper $imageHelper
     * @param MinorUnits $minorUnits
     */
    public function __construct(
        private readonly OrderLineItemInterfaceFactory $lineItemFactory,
        private readonly OrderLineItemQuantityInterfaceFactory $quantityFactory,
        private readonly ItemResponseInterfaceFactory $itemFactory,
        private readonly TotalResponseInterfaceFactory $totalFactory,
        private readonly CollectionFactory $productCollectionFactory,
        private readonly ImageHelper $imageHelper,
        private readonly MinorUnits $minorUnits
    ) {
    }

    /**
     * Looks the pictures up for a whole order in one product load, so a long order does not load a
     * product per line. A product deleted since the order was placed is simply absent from the result.
     *
     * @param OrderItemInterface[] $orderItems
     * @return array<int, string> Product id to the URL of its picture
     */
    public function imageUrls(array $orderItems): array
    {
        $productIds = [];
        $storeId = 0;

        foreach ($orderItems as $orderItem) {
            $productId = (int) $orderItem->getProductId();

            if ($productId === 0) {
                continue;
            }

            $productIds[$productId] = $productId;
            // Every item of an order belongs to the same store.
            $storeId = (int) $orderItem->getStoreId();
        }

        if ($productIds === []) {
            return [];
        }

        $urls = [];

        foreach ($this->productCollection($productIds, $storeId)->getItems() as $product) {
            if (!$product instanceof Product) {
                continue;
            }

            $url = $this->imageUrlOf($product);

            if ($url !== null) {
                $urls[(int) $product->getId()] = $url;
            }
        }

        return $urls;
    }

    /**
     * @param OrderItemInterface $orderItem
     * @param string $currencyCode
     * @param string $lineItemId Identifier the response exposes for this item
     * @param string|null $parentId Identifier of the enclosing line item, for nested products
     * @param string|null $imageUrl Picture already looked up for this item, from `imageUrls()`
     * @return OrderLineItemInterface
     */
    public function convert(
        OrderItemInterface $orderItem,
        string $currencyCode,
        string $lineItemId,
        ?string $parentId = null,
        ?string $imageUrl = null
    ): OrderLineItemInterface {
        $quantity = $this->convertQuantity($orderItem);

        /** @var OrderLineItemInterface $lineItem */
        $lineItem = $this->lineItemFactory->create();
        $lineItem->setId($lineItemId);
        $lineItem->setItem($this->convertItem($orderItem, $currencyCode, $imageUrl));
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
     * @param string|null $imageUrl
     * @return ItemResponseInterface
     */
    public function convertItem(
        OrderItemInterface $orderItem,
        string $currencyCode,
        ?string $imageUrl = null
    ): ItemResponseInterface {
        /** @var ItemResponseInterface $item */
        $item = $this->itemFactory->create();
        $item->setId((string) $orderItem->getSku());
        $item->setTitle((string) $orderItem->getName());
        $item->setPrice($this->minorUnits->convert((float) $orderItem->getPrice(), $currencyCode));

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
     * @param int[] $productIds
     * @param int $storeId
     * @return ProductCollection
     */
    private function productCollection(array $productIds, int $storeId): ProductCollection
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addIdFilter(array_values($productIds));
        $collection->addAttributeToSelect(['image', 'small_image', 'thumbnail']);
        $collection->setStoreId($storeId);
        $collection->setFlag(self::STOCK_FILTER_FLAG, true);

        return $collection;
    }

    /**
     * A picture that cannot be built costs the agent only the thumbnail, so it is not worth failing on.
     *
     * @param Product $product
     * @return string|null
     */
    private function imageUrlOf(Product $product): ?string
    {
        try {
            return $this->imageHelper->init($product, self::IMAGE_ID)->getUrl() ?: null;
        } catch (\Exception $exception) {
            return null;
        }
    }
}
