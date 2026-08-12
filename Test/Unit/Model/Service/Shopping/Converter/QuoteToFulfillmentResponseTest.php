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

use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentDestinationResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentGroupResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentMethodResponseInterface;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentMethodResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentOptionResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\TotalResponseInterfaceFactory;
use Magebit\UcpSpec\Data\Shopping\Types\FulfillmentDestinationResponse;
use Magebit\UcpSpec\Data\Shopping\Types\FulfillmentGroupResponse;
use Magebit\UcpSpec\Data\Shopping\Types\FulfillmentMethodResponse;
use Magebit\UcpSpec\Data\Shopping\Types\FulfillmentOptionResponse;
use Magebit\UcpSpec\Data\Shopping\Types\FulfillmentResponse;
use Magebit\UcpSpec\Data\Shopping\Types\TotalResponse;
use Magebit\AgenticCore\Model\Money\MinorUnits;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\QuoteToFulfillmentResponse;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Quote\Api\Data\CurrencyInterface;
use PHPUnit\Framework\TestCase;

class QuoteToFulfillmentResponseTest extends TestCase
{
    private QuoteToFulfillmentResponse $converter;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->converter = new QuoteToFulfillmentResponse(
            $this->factory(FulfillmentResponseInterfaceFactory::class, FulfillmentResponse::class),
            $this->factory(FulfillmentMethodResponseInterfaceFactory::class, FulfillmentMethodResponse::class),
            $this->factory(FulfillmentGroupResponseInterfaceFactory::class, FulfillmentGroupResponse::class),
            $this->factory(FulfillmentOptionResponseInterfaceFactory::class, FulfillmentOptionResponse::class),
            $this->factory(
                FulfillmentDestinationResponseInterfaceFactory::class,
                FulfillmentDestinationResponse::class
            ),
            $this->factory(TotalResponseInterfaceFactory::class, TotalResponse::class),
            new MinorUnits()
        );
    }

    /**
     * With nothing submitted the merchant names the method and group itself.
     *
     * @return void
     */
    public function testDefaultIdentifiersAreUsedWhenNothingWasSubmitted(): void
    {
        $methods = $this->converter->getMethods($this->address(), ['1']);

        $this->assertSame('shipping', $methods[0]->getId());
        $this->assertSame(FulfillmentMethodResponseInterface::TYPE_SHIPPING, $methods[0]->getType());
        $this->assertSame('package', $methods[0]->getGroups()[0]->getId());
    }

    /**
     * Agents index responses by the identifiers they sent, so those have to come back unchanged.
     *
     * @return void
     */
    public function testSubmittedIdentifiersAreEchoedBack(): void
    {
        $methods = $this->converter->getMethods($this->address(), ['1'], [
            'id' => 'agent_method_1',
            'type' => FulfillmentMethodResponseInterface::TYPE_SHIPPING,
            'groups' => [['id' => 'agent_group_1']],
        ]);

        $this->assertSame('agent_method_1', $methods[0]->getId());
        $this->assertSame('agent_group_1', $methods[0]->getGroups()[0]->getId());
    }

    /**
     * @return void
     */
    public function testSubmittedSelectionsWinOverTheQuoteState(): void
    {
        $methods = $this->converter->getMethods($this->address(), ['1'], [
            'selected_destination_id' => 'agent_destination',
            'groups' => [['id' => 'g1', 'selected_option_id' => 'flatrate_flatrate']],
        ]);

        $this->assertSame('agent_destination', $methods[0]->getSelectedDestinationId());
        $this->assertSame('flatrate_flatrate', $methods[0]->getGroups()[0]->getSelectedOptionId());
    }

    /**
     * A blank identifier is not a choice, so the merchant default still applies.
     *
     * @return void
     */
    public function testBlankSubmittedIdentifierFallsBackToTheDefault(): void
    {
        $methods = $this->converter->getMethods($this->address(), ['1'], ['id' => '', 'groups' => [['id' => '']]]);

        $this->assertSame('shipping', $methods[0]->getId());
        $this->assertSame('package', $methods[0]->getGroups()[0]->getId());
    }

    /**
     * @return void
     */
    public function testComputedOptionsAreStillAttachedToASubmittedGroup(): void
    {
        $methods = $this->converter->getMethods($this->address(), ['1'], ['groups' => [['id' => 'agent_group_1']]]);
        $options = $methods[0]->getGroups()[0]->getOptions();

        $this->assertCount(1, $options);
        $this->assertSame('flatrate_flatrate', $options[0]->getId());
        $this->assertSame(599, $options[0]->getTotals()[0]->getAmount());
    }

    /**
     * @param class-string $factoryClass Factory interface to stub
     * @param class-string $dtoClass Generated DTO the factory returns
     * @return object
     */
    private function factory(string $factoryClass, string $dtoClass): object
    {
        $factory = $this->createMock($factoryClass);
        $factory->method('create')->willReturnCallback(static fn (): object => new $dtoClass());

        return $factory;
    }

    /**
     * @return Address
     */
    private function address(): Address
    {
        $rate = $this->getMockBuilder(Rate::class)
            ->disableOriginalConstructor()
            ->addMethods([
                'getCarrier',
                'getMethod',
                'getMethodTitle',
                'getCarrierTitle',
                'getPrice',
                'getErrorMessage',
            ])
            ->getMock();
        $rate->method('getErrorMessage')->willReturn(null);
        $rate->method('getCarrier')->willReturn('flatrate');
        $rate->method('getMethod')->willReturn('flatrate');
        $rate->method('getMethodTitle')->willReturn('Fixed');
        $rate->method('getCarrierTitle')->willReturn('Flat Rate');
        $rate->method('getPrice')->willReturn(5.99);

        $currency = $this->createMock(CurrencyInterface::class);
        $currency->method('getStoreCurrencyCode')->willReturn('EUR');

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCurrency'])
            ->getMock();
        $quote->method('getCurrency')->willReturn($currency);

        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getAllShippingRates',
                'getQuote',
                'getCountryId',
                'getStreet',
                'getId',
                'getShippingMethod',
            ])
            ->getMock();
        $address->method('getAllShippingRates')->willReturn([$rate]);
        $address->method('getQuote')->willReturn($quote);
        $address->method('getCountryId')->willReturn('LV');
        $address->method('getStreet')->willReturn(['Brivibas 1']);
        $address->method('getId')->willReturn(7);
        $address->method('getShippingMethod')->willReturn(null);

        return $address;
    }
}
