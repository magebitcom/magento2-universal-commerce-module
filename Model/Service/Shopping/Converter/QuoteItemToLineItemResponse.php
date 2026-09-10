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
use Magebit\UcpSpec\Api\Shopping\Types\LineItemResponseInterface;
use Magebit\UcpSpec\Api\Shopping\Types\ItemResponseInterface;
use Magebit\UcpSpec\Api\Shopping\Types\TotalResponseInterface;
use Magebit\UniversalCommerce\Api\Data\TotalTypeInterface;
use Magebit\UcpSpec\Api\Shopping\Types\LineItemResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\ItemResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\TotalResponseInterfaceFactory;
use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Quote\Model\Quote\Item as QuoteItem;

class QuoteItemToLineItemResponse
{
    public function __construct(
        protected readonly LineItemResponseInterfaceFactory $lineItemResponseFactory,
        protected readonly ItemResponseInterfaceFactory $itemResponseFactory,
        protected readonly TotalResponseInterfaceFactory $totalResponseFactory,
        protected readonly ImageHelper $imageHelper,
        protected readonly MinorUnits $minorUnits
    ) {
    }

    /**
     * @param CartItemInterface $quoteItem
     * @return LineItemResponseInterface
     */
    public function convert(CartItemInterface $quoteItem): LineItemResponseInterface
    {
        $lineItem = $this->lineItemResponseFactory->create();
        $lineItem->setId((string) $quoteItem->getItemId());
        $lineItem->setQuantity((int) $quoteItem->getQty());
        $lineItem->setItem($this->convertItem($quoteItem));
        $lineItem->setTotals($this->convertTotals($quoteItem));

        return $lineItem;
    }

    /**
     * Convert quote item to item response
     *
     * @param CartItemInterface $quoteItem
     * @return ItemResponseInterface
     */
    public function convertItem(CartItemInterface $quoteItem): ItemResponseInterface
    {
        /** @var QuoteItem $quoteItem */
        $product = $quoteItem->getProduct();

        $item = $this->itemResponseFactory->create();
        $item->setId($product->getSku());
        $item->setTitle($product->getName());
        $item->setPrice($this->minorUnits->convert(
            (float) $quoteItem->getPrice(),
            $this->getCurrencyCode($quoteItem)
        ));

        // Get proper product image URL
        $imageUrl = $this->getProductImageUrl($quoteItem);
        if ($imageUrl) {
            $item->setImageUrl($imageUrl);
        }

        return $item;
    }

    /**
     * Convert quote item totals
     *
     * @param CartItemInterface $quoteItem
     * @return TotalResponseInterface[]
     */
    public function convertTotals(CartItemInterface $quoteItem): array
    {
        /** @var QuoteItem $quoteItem */
        $totals = [];
        $currencyCode = $this->getCurrencyCode($quoteItem);

        // Subtotal (price * quantity before discounts)
        $subtotal = (float) $quoteItem->getRowTotal();
        if ($subtotal > 0) {
            $total = $this->totalResponseFactory->create();
            $total->setType(TotalTypeInterface::TYPE_SUBTOTAL);
            $total->setAmount($this->minorUnits->convert($subtotal, $currencyCode));
            $total->setDisplayText('Subtotal');
            $totals[] = $total;
        }

        // Item-level discount, emitted negative: the spec constrains `items_discount` to
        // `exclusiveMaximum: 0`, so the sign is part of the value rather than presentation.
        $discountAmount = abs((float) $quoteItem->getDiscountAmount());
        if ($discountAmount > 0) {
            $total = $this->totalResponseFactory->create();
            $total->setType(TotalTypeInterface::TYPE_ITEMS_DISCOUNT);
            $total->setAmount($this->minorUnits->convert(-$discountAmount, $currencyCode));
            $total->setDisplayText('Discount');
            $totals[] = $total;
        }

        // Total (including tax)
        $rowTotal = (float) $quoteItem->getRowTotalInclTax();
        $total = $this->totalResponseFactory->create();
        $total->setType(TotalTypeInterface::TYPE_TOTAL);
        $total->setAmount($this->minorUnits->convert($rowTotal, $currencyCode));
        $total->setDisplayText('Total');
        $totals[] = $total;

        return $totals;
    }

    /**
     * Currency the quote's amounts are expressed in, matching the code reported on the response.
     *
     * @param CartItemInterface $quoteItem
     * @return string
     */
    private function getCurrencyCode(CartItemInterface $quoteItem): string
    {
        /** @var QuoteItem $quoteItem */
        return $quoteItem->getQuote()->getCurrency()?->getStoreCurrencyCode() ?? 'USD';
    }

    /**
     * Get product image URL
     *
     * @param CartItemInterface $quoteItem
     * @return string|null
     */
    public function getProductImageUrl(CartItemInterface $quoteItem): ?string
    {
        /** @var QuoteItem $quoteItem */
        $product = $quoteItem->getProduct();

        try {
            $imageUrl = $this->imageHelper
                ->init($product, 'product_base_image')
                ->getUrl();

            return $imageUrl ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
