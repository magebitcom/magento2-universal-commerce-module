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

use Magebit\AgenticCore\Model\Buyer\BuyerResolver;
use Magebit\UcpSpec\Api\Shopping\BuyerConsentResponseConsentInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\BuyerInterfaceFactory;
use Magebit\UcpSpec\Data\Shopping\BuyerConsentResponseConsent;
use Magebit\UniversalCommerce\Api\Service\Shopping\BuyerWithConsentInterface;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\QuoteToBuyerResponse;
use Magebit\UniversalCommerce\Model\Spec\Schemas\Shopping\BuyerWithConsent;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class QuoteToBuyerResponseTest extends TestCase
{
    private QuoteToBuyerResponse $converter;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $buyerFactory = $this->createMock(BuyerInterfaceFactory::class);
        $buyerFactory->method('create')->willReturnCallback(
            static fn (): BuyerWithConsent => new BuyerWithConsent()
        );

        $consentFactory = $this->createMock(BuyerConsentResponseConsentInterfaceFactory::class);
        $consentFactory->method('create')->willReturnCallback(
            static fn (array $arguments = []): BuyerConsentResponseConsent
                => new BuyerConsentResponseConsent($arguments['data'] ?? [])
        );

        $this->converter = new QuoteToBuyerResponse(
            $buyerFactory,
            new BuyerResolver(),
            $consentFactory
        );
    }

    /**
     * @return void
     */
    public function testTheRecordedConsentIsReportedBackFlagByFlag(): void
    {
        $buyer = $this->converter->convert($this->quote('ada@example.test'), [
            'marketing' => true,
            'analytics' => false,
            'sale_of_data' => false,
        ]);

        $this->assertInstanceOf(BuyerWithConsentInterface::class, $buyer);
        $consent = $buyer->getConsent();
        $this->assertNotNull($consent);
        $this->assertTrue($consent->getMarketing());
        $this->assertFalse($consent->getAnalytics());
        $this->assertFalse($consent->getSaleOfData());
    }

    /**
     * @return void
     */
    public function testABuyerWithNoRecordedConsentCarriesNone(): void
    {
        $buyer = $this->converter->convert($this->quote('ada@example.test'));

        $this->assertInstanceOf(BuyerWithConsentInterface::class, $buyer);
        $this->assertNull($buyer->getConsent());
    }

    /**
     * @return void
     */
    public function testAnEmptyBuyerIsOmittedRatherThanSentEmpty(): void
    {
        $this->assertNull($this->converter->convert($this->quote(null)));
    }

    /**
     * Consent is a buyer fact of its own, so a checkout that has only consent still reports a buyer.
     *
     * @return void
     */
    public function testConsentAloneIsEnoughToReportABuyer(): void
    {
        $buyer = $this->converter->convert($this->quote(null), ['marketing' => true]);

        $this->assertInstanceOf(BuyerWithConsentInterface::class, $buyer);
        $this->assertTrue($buyer->getConsent()?->getMarketing());
    }

    /**
     * @param string|null $email
     * @return Quote&MockObject
     */
    private function quote(?string $email): Quote&MockObject
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getFirstname', 'getLastname', 'getEmail', 'getTelephone'])
            ->getMock();

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            // All three customer accessors are magic on Quote rather than declared.
            ->addMethods(['getCustomerFirstname', 'getCustomerLastname', 'getCustomerEmail'])
            ->onlyMethods(['getBillingAddress', 'getShippingAddress'])
            ->getMock();
        $quote->method('getCustomerFirstname')->willReturn(null);
        $quote->method('getCustomerLastname')->willReturn(null);
        $quote->method('getCustomerEmail')->willReturn($email);
        $quote->method('getBillingAddress')->willReturn($address);
        $quote->method('getShippingAddress')->willReturn($address);

        return $quote;
    }
}
