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

use Magebit\AgenticCore\Model\Money\MinorUnits;
use Magebit\UcpSpec\Api\Shopping\Types\ItemResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\LineItemResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\TotalResponseInterface;
use Magebit\UcpSpec\Api\Shopping\Types\TotalResponseInterfaceFactory;
use Magebit\UcpSpec\Data\Shopping\Types\ItemResponse;
use Magebit\UcpSpec\Data\Shopping\Types\LineItemResponse;
use Magebit\UcpSpec\Data\Shopping\Types\TotalResponse;
use Magebit\UniversalCommerce\Api\Data\TotalTypeInterface;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\QuoteItemToLineItemResponse;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Quote\Api\Data\CurrencyInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use PHPUnit\Framework\TestCase;

class QuoteItemToLineItemResponseTest extends TestCase
{
    /** @var QuoteItemToLineItemResponse */
    private QuoteItemToLineItemResponse $converter;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $lineItemFactory = $this->createMock(LineItemResponseInterfaceFactory::class);
        $lineItemFactory->method('create')->willReturnCallback(fn (): LineItemResponse => new LineItemResponse());

        $itemFactory = $this->createMock(ItemResponseInterfaceFactory::class);
        $itemFactory->method('create')->willReturnCallback(fn (): ItemResponse => new ItemResponse());

        $totalFactory = $this->createMock(TotalResponseInterfaceFactory::class);
        $totalFactory->method('create')->willReturnCallback(fn (): TotalResponse => new TotalResponse());

        $imageHelper = $this->createMock(ImageHelper::class);
        $imageHelper->method('init')->willReturnSelf();
        $imageHelper->method('getUrl')->willReturn('https://merchant.test/media/sku.jpg');

        $this->converter = new QuoteItemToLineItemResponse(
            $lineItemFactory,
            $itemFactory,
            $totalFactory,
            $imageHelper,
            new MinorUnits()
        );
    }

    /**
     * A line item's totals are the same `total_resp` entries as the checkout's, so `items_discount`
     * carries the same `exclusiveMaximum: 0` and must be negative.
     *
     * @return void
     */
    public function testTheItemDiscountIsEmittedAsANegativeAmount(): void
    {
        $totals = $this->totalsOf($this->quoteItem(discountAmount: 5.00));

        $this->assertSame(-500, $totals[TotalTypeInterface::TYPE_ITEMS_DISCOUNT]);
    }

    /**
     * @return void
     */
    public function testTheRemainingItemTotalsStayPositive(): void
    {
        $totals = $this->totalsOf($this->quoteItem(discountAmount: 5.00));

        $this->assertSame(4000, $totals[TotalTypeInterface::TYPE_SUBTOTAL]);
        $this->assertSame(3850, $totals[TotalTypeInterface::TYPE_TOTAL]);
    }

    /**
     * An item with nothing taken off must not emit a zero discount, which would read as a discount
     * that applied and is also not a legal negative amount.
     *
     * @return void
     */
    public function testAnUndiscountedItemEmitsNoDiscountEntry(): void
    {
        $totals = $this->totalsOf($this->quoteItem(discountAmount: 0.00));

        $this->assertArrayNotHasKey(TotalTypeInterface::TYPE_ITEMS_DISCOUNT, $totals);
    }

    /**
     * @param QuoteItem $quoteItem
     * @return array<string, int>
     */
    private function totalsOf(QuoteItem $quoteItem): array
    {
        $result = [];

        foreach ($this->converter->convertTotals($quoteItem) as $total) {
            /** @var TotalResponseInterface $total */
            $result[$total->getType()] = $total->getAmount();
        }

        return $result;
    }

    /**
     * @param float $discountAmount
     * @return QuoteItem
     */
    private function quoteItem(float $discountAmount): QuoteItem
    {
        $currency = $this->createMock(CurrencyInterface::class);
        $currency->method('getStoreCurrencyCode')->willReturn('USD');

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCurrency'])
            ->getMock();
        $quote->method('getCurrency')->willReturn($currency);

        // Row totals and the item discount resolve through __call, so they must be added.
        $item = $this->getMockBuilder(QuoteItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuote'])
            ->addMethods(['getRowTotal', 'getDiscountAmount', 'getRowTotalInclTax'])
            ->getMock();
        $item->method('getQuote')->willReturn($quote);
        $item->method('getRowTotal')->willReturn(40.00);
        $item->method('getDiscountAmount')->willReturn($discountAmount);
        $item->method('getRowTotalInclTax')->willReturn(40.00 - $discountAmount + 3.50);

        return $item;
    }
}
