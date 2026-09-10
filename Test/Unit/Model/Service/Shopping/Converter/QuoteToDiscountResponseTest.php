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

use Magebit\UcpSpec\Api\Shopping\DiscountResponseAppliedDiscountInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\DiscountResponseDiscountsObjectInterfaceFactory;
use Magebit\UcpSpec\Data\Shopping\DiscountResponseAppliedDiscount;
use Magebit\UcpSpec\Data\Shopping\DiscountResponseDiscountsObject;
use Magebit\AgenticCore\Model\Money\MinorUnits;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\QuoteToDiscountResponse;
use Magento\Quote\Api\Data\CurrencyInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\TestCase;

class QuoteToDiscountResponseTest extends TestCase
{
    private QuoteToDiscountResponse $converter;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $discountsFactory = $this->createMock(DiscountResponseDiscountsObjectInterfaceFactory::class);
        $discountsFactory->method('create')
            ->willReturnCallback(fn (): DiscountResponseDiscountsObject => new DiscountResponseDiscountsObject());

        $appliedFactory = $this->createMock(DiscountResponseAppliedDiscountInterfaceFactory::class);
        $appliedFactory->method('create')
            ->willReturnCallback(fn (): DiscountResponseAppliedDiscount => new DiscountResponseAppliedDiscount());

        $this->converter = new QuoteToDiscountResponse($discountsFactory, $appliedFactory, new MinorUnits());
    }

    /**
     * An automatic discount carries no coupon code, so a sign mistake on the amount is the only
     * thing standing between reporting it and dropping it entirely.
     *
     * @return void
     */
    public function testAutomaticDiscountIsReportedWithoutACouponCode(): void
    {
        $discounts = $this->converter->convert($this->quote(subtotal: 100.00, subtotalWithDiscount: 85.00));

        $this->assertNotNull($discounts);
        $this->assertSame([], $discounts->getCodes());

        $applied = $discounts->getApplied();
        $this->assertCount(1, $applied);
        $this->assertSame(1500, $applied[0]->getAmount());
        $this->assertTrue($applied[0]->getAutomatic());
    }

    /**
     * @return void
     */
    public function testAppliedAmountIsPositiveInMinorUnits(): void
    {
        $discounts = $this->converter->convert(
            $this->quote(subtotal: 100.00, subtotalWithDiscount: 80.01, couponCode: '20OFF')
        );

        $this->assertNotNull($discounts);
        $this->assertSame(1999, $discounts->getApplied()[0]->getAmount());
        $this->assertGreaterThan(0, $discounts->getApplied()[0]->getAmount());
    }

    /**
     * @return void
     */
    public function testCouponCodeIsEchoedAndCarriedOnTheAppliedDiscount(): void
    {
        $discounts = $this->converter->convert(
            $this->quote(subtotal: 50.00, subtotalWithDiscount: 45.00, couponCode: '10OFF')
        );

        $this->assertNotNull($discounts);
        $this->assertSame(['10OFF'], $discounts->getCodes());
        $this->assertSame('10OFF', $discounts->getApplied()[0]->getCode());
        $this->assertFalse($discounts->getApplied()[0]->getAutomatic());
    }

    /**
     * A shipping discount is a discount too, and it is the one Magento keeps on the address.
     *
     * @return void
     */
    public function testShippingDiscountIsIncluded(): void
    {
        $discounts = $this->converter->convert(
            $this->quote(subtotal: 100.00, subtotalWithDiscount: 100.00, shippingDiscount: 5.00)
        );

        $this->assertNotNull($discounts);
        $this->assertSame(500, $discounts->getApplied()[0]->getAmount());
    }

    /**
     * A rejected code still has to be echoed, so the agent can see it did not apply.
     *
     * @return void
     */
    public function testRejectedCodeIsEchoedWithNoAppliedDiscount(): void
    {
        $discounts = $this->converter->convert(
            $this->quote(subtotal: 100.00, subtotalWithDiscount: 100.00, couponCode: 'BOGUS')
        );

        $this->assertNotNull($discounts);
        $this->assertSame(['BOGUS'], $discounts->getCodes());
        $this->assertSame([], $discounts->getApplied());
    }

    /**
     * @return void
     */
    public function testNoDiscountAndNoCodeIsOmitted(): void
    {
        $this->assertNull($this->converter->convert($this->quote(subtotal: 100.00, subtotalWithDiscount: 100.00)));
    }

    /**
     * A virtual cart keeps its discount on the billing address, and it must still be reported.
     *
     * @return void
     */
    public function testVirtualQuoteReportsDiscountsFromTheBillingAddress(): void
    {
        $discounts = $this->converter->convert(
            $this->quote(subtotal: 100.00, subtotalWithDiscount: 90.00, isVirtual: true)
        );

        $this->assertNotNull($discounts);
        $this->assertSame(1000, $discounts->getApplied()[0]->getAmount());
    }

    /**
     * @param float $subtotal Quote subtotal before discount
     * @param float $subtotalWithDiscount Quote subtotal after discount
     * @param string|null $couponCode Coupon code on the quote
     * @param float $shippingDiscount Shipping discount on the address, as a positive magnitude
     * @param bool $isVirtual Whether the quote is virtual, which moves the discount to billing
     * @return Quote
     */
    private function quote(
        float $subtotal,
        float $subtotalWithDiscount,
        ?string $couponCode = null,
        float $shippingDiscount = 0.0,
        bool $isVirtual = false
    ): Quote {
        $currency = $this->createMock(CurrencyInterface::class);
        $currency->method('getStoreCurrencyCode')->willReturn('EUR');

        // These resolve through __call on a DataObject, so they have to be added rather than stubbed.
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->addMethods(['getShippingDiscountAmount', 'getDiscountDescription'])
            ->getMock();
        // Magento reports address discounts as a negative value.
        $address->method('getShippingDiscountAmount')->willReturn(-$shippingDiscount);
        $address->method('getDiscountDescription')->willReturn($couponCode);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCurrency', 'getShippingAddress', 'getBillingAddress', 'getIsVirtual'])
            ->addMethods(['getSubtotal', 'getSubtotalWithDiscount', 'getCouponCode'])
            ->getMock();
        $quote->method('getCurrency')->willReturn($currency);
        $quote->method('getSubtotal')->willReturn($subtotal);
        $quote->method('getSubtotalWithDiscount')->willReturn($subtotalWithDiscount);
        $quote->method('getCouponCode')->willReturn($couponCode);
        $quote->method('getIsVirtual')->willReturn($isVirtual);
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getBillingAddress')->willReturn($address);

        return $quote;
    }
}
