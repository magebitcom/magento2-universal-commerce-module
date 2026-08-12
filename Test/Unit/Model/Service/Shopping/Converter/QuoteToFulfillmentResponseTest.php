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

use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentDestinationResponseInterface;
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
use Magebit\AgenticCore\Model\Fulfillment\ShippingOption;
use Magebit\AgenticCore\Model\Fulfillment\ShippingOptionResolver;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\QuoteToFulfillmentResponse;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\TestCase;

class QuoteToFulfillmentResponseTest extends TestCase
{
    private const AMOUNT_EXCL_TAX = 599;
    private const TAX_AMOUNT = 126;
    private const AMOUNT_INCL_TAX = 725;

    private QuoteToFulfillmentResponse $converter;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $resolver = $this->createMock(ShippingOptionResolver::class);
        $resolver->method('resolve')->willReturn([
            new ShippingOption(
                'flatrate_flatrate',
                'Fixed',
                'Flat Rate',
                'Flat Rate',
                self::AMOUNT_EXCL_TAX,
                self::TAX_AMOUNT,
                self::AMOUNT_INCL_TAX
            ),
        ]);

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
            $resolver
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
            'destinations' => [['id' => 'agent_destination']],
            'selected_destination_id' => 'agent_destination',
            'groups' => [['id' => 'g1', 'selected_option_id' => 'flatrate_flatrate']],
        ]);

        $this->assertSame('agent_destination', $methods[0]->getSelectedDestinationId());
        $this->assertSame('flatrate_flatrate', $methods[0]->getGroups()[0]->getSelectedOptionId());
    }

    /**
     * There is one address on the quote, so a selection naming something the agent never described has
     * nothing to point at — the response names the destination it does list rather than echoing a
     * reference that resolves to nothing.
     *
     * @return void
     */
    public function testASelectionNamingAnUnlistedDestinationIsNotEchoed(): void
    {
        $methods = $this->converter->getMethods($this->address(), ['1'], [
            'selected_destination_id' => 'never_submitted',
        ]);

        $this->assertNotSame('never_submitted', $methods[0]->getSelectedDestinationId());
        $this->assertSame(
            $methods[0]->getDestinations()[0]->getId(),
            $methods[0]->getSelectedDestinationId()
        );
    }

    /**
     * The selection has to name a destination the response lists. Echoing the agent's identifier while
     * renaming the destination to the quote address id left the reference pointing at nothing.
     *
     * @return void
     */
    public function testTheSelectedDestinationIsOneOfTheListedDestinations(): void
    {
        $methods = $this->converter->getMethods($this->address(), ['1'], [
            'destinations' => [['id' => 'agent_destination']],
            'selected_destination_id' => 'agent_destination',
        ]);

        $this->assertSame(
            [$methods[0]->getSelectedDestinationId()],
            array_map(
                fn (FulfillmentDestinationResponseInterface $destination): string => $destination->getId(),
                $methods[0]->getDestinations()
            )
        );
    }

    /**
     * With no identifier submitted, the response invents one and points the selection at it.
     *
     * @return void
     */
    public function testAnUnnamedDestinationStillMatchesTheSelection(): void
    {
        $methods = $this->converter->getMethods($this->address(), ['1']);

        $this->assertSame(
            $methods[0]->getDestinations()[0]->getId(),
            $methods[0]->getSelectedDestinationId()
        );
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
    }

    /**
     * The schema types this total as `fulfillment`, which a consumer adds to the order total, so it
     * carries the incl-tax amount. It previously carried the excl-tax one and under-quoted shipping
     * wherever shipping is taxed.
     *
     * @return void
     */
    public function testTheFulfillmentTotalCarriesTheInclTaxAmount(): void
    {
        $options = $this->converter->getMethods($this->address(), ['1'])[0]->getGroups()[0]->getOptions();

        $this->assertSame(self::AMOUNT_INCL_TAX, $options[0]->getTotals()[0]->getAmount());
        $this->assertNotSame(self::AMOUNT_EXCL_TAX, $options[0]->getTotals()[0]->getAmount());
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
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods([])->getMock();

        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getQuote',
                'getCountryId',
                'getStreet',
                'getId',
                'getShippingMethod',
            ])
            ->getMock();
        $address->method('getQuote')->willReturn($quote);
        $address->method('getCountryId')->willReturn('LV');
        $address->method('getStreet')->willReturn(['Brivibas 1']);
        $address->method('getId')->willReturn(7);
        $address->method('getShippingMethod')->willReturn(null);

        return $address;
    }
}
