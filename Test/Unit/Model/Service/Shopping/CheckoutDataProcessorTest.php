<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit\Model\Service\Shopping;

use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentDestinationRequestInterface;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentGroupCreateRequestInterface;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentMethodCreateRequestInterface;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentRequestInterface;
use Magebit\UcpSpec\Api\Shopping\Types\ItemCreateRequestInterface;
use Magebit\UcpSpec\Api\Shopping\Types\LineItemCreateRequestInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterfaceFactory;
use Magebit\AgenticCore\Model\Quote\AddressWriter;
use Magebit\AgenticCore\Model\Quote\LineItemOutcome;
use Magebit\AgenticCore\Model\Quote\LineItemResult;
use Magebit\AgenticCore\Model\Quote\LineItemWriter;
use Magebit\AgenticCore\Model\Quote\PersonalInformationCopier;
use Magebit\AgenticCore\Model\Quote\ShippingMethodWriter;
use Magebit\UniversalCommerce\Model\Service\Shopping\AgentProfileParser;
use Magebit\UniversalCommerce\Model\Service\Shopping\CheckoutDataProcessor;
use Magento\Framework\App\Request\Http;
use Magento\Quote\Api\GuestCouponManagementInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CheckoutDataProcessorTest extends TestCase
{
    private const STORE_ID = 7;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $createdMessages = [];

    /**
     * @var LineItemWriter&MockObject
     */
    private LineItemWriter $lineItemWriter;

    /**
     * @var ShippingMethodWriter&MockObject
     */
    private ShippingMethodWriter $shippingMethodWriter;

    /**
     * @var AgentProfileParser&MockObject
     */
    private AgentProfileParser $agentProfileParser;

    /**
     * @var Http&MockObject
     */
    private Http $httpRequest;

    /**
     * @var CheckoutDataProcessor
     */
    private CheckoutDataProcessor $processor;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->createdMessages = [];
        $this->lineItemWriter = $this->createMock(LineItemWriter::class);
        $this->shippingMethodWriter = $this->createMock(ShippingMethodWriter::class);

        $messageFactory = $this->createMock(MessageInterfaceFactory::class);
        $messageFactory->method('create')->willReturnCallback(function (array $arguments) {
            $this->createdMessages[] = $arguments['data'];
            return $this->createMock(MessageInterface::class);
        });

        $this->agentProfileParser = $this->createMock(AgentProfileParser::class);
        $this->httpRequest = $this->createMock(Http::class);

        $this->processor = new CheckoutDataProcessor(
            $this->lineItemWriter,
            new AddressWriter(),
            new PersonalInformationCopier(),
            $this->shippingMethodWriter,
            $this->createMock(GuestCouponManagementInterface::class),
            $this->agentProfileParser,
            $this->httpRequest,
            $messageFactory
        );
    }

    /**
     * Every UCP destination field must land on the quote address field Magento actually reads.
     *
     * @return void
     */
    public function testAddDestinationToCartMapsEverySubmittedField(): void
    {
        $shippingAddress = $this->createAddress();
        $shippingAddress->expects($this->once())->method('setStreet')->with(['1 Main St', 'Apt 2']);
        $shippingAddress->expects($this->once())->method('setCity')->with('Austin');
        $shippingAddress->expects($this->once())->method('setRegion')->with('TX');
        $shippingAddress->expects($this->once())->method('setCountryId')->with('US');
        $shippingAddress->expects($this->once())->method('setPostcode')->with('78701');
        $shippingAddress->expects($this->once())->method('setFirstname')->with('Ada');
        $shippingAddress->expects($this->once())->method('setLastname')->with('Lovelace');
        $shippingAddress->expects($this->once())->method('setTelephone')->with('+15555550123');
        $shippingAddress->expects($this->once())->method('setCollectShippingRates')->with(true);

        $this->processor->addDestinationToCart(
            $this->createQuote($shippingAddress),
            $this->createDestination('d1', ['street_address' => '1 Main St', 'extended_address' => 'Apt 2'])
        );
    }

    /**
     * @return void
     */
    public function testAddDestinationToCartSkipsFieldsThatWereNotSubmitted(): void
    {
        $destination = $this->createMock(FulfillmentDestinationRequestInterface::class);
        $destination->method('getAddressLocality')->willReturn('Riga');

        $shippingAddress = $this->createAddress();
        $shippingAddress->expects($this->never())->method('setStreet');
        $shippingAddress->expects($this->never())->method('setCountryId');
        $shippingAddress->expects($this->once())->method('setCity')->with('Riga');

        $this->processor->addDestinationToCart($this->createQuote($shippingAddress), $destination);
    }

    /**
     * @return void
     */
    public function testProcessFulfillmentInformationAppliesTheSelectedDestination(): void
    {
        $shippingAddress = $this->createAddress();
        $shippingAddress->expects($this->once())->method('setCity')->with('Berlin');

        $method = $this->createShippingMethod(
            [
                $this->createDestination('d1', ['address_locality' => 'Austin']),
                $this->createDestination('d2', ['address_locality' => 'Berlin']),
            ],
            'd2'
        );

        $this->processor->processFulfillmentInformation(
            $this->createQuote($shippingAddress),
            $this->createFulfillment([$method])
        );
    }

    /**
     * @return void
     */
    public function testProcessFulfillmentInformationFallsBackToTheFirstDestination(): void
    {
        $shippingAddress = $this->createAddress();
        $shippingAddress->expects($this->once())->method('setCity')->with('Austin');

        $method = $this->createShippingMethod([
            $this->createDestination('d1', ['address_locality' => 'Austin']),
            $this->createDestination('d2', ['address_locality' => 'Berlin']),
        ]);

        $this->processor->processFulfillmentInformation(
            $this->createQuote($shippingAddress),
            $this->createFulfillment([$method])
        );
    }

    /**
     * @return void
     */
    public function testProcessFulfillmentInformationIgnoresNonShippingMethods(): void
    {
        $pickup = $this->createMock(FulfillmentMethodCreateRequestInterface::class);
        $pickup->method('getType')->willReturn('pickup');
        $pickup->method('getDestinations')
            ->willReturn([$this->createDestination('d1', ['address_locality' => 'Austin'])]);

        $shippingAddress = $this->createAddress();
        $shippingAddress->expects($this->never())->method('setCity');

        $this->processor->processFulfillmentInformation(
            $this->createQuote($shippingAddress),
            $this->createFulfillment([$pickup])
        );
    }

    /**
     * A method without `type` must be skipped rather than escalate the DTO's exception into a 500.
     *
     * @return void
     */
    public function testProcessFulfillmentInformationSkipsMethodsWithoutAType(): void
    {
        $untyped = $this->createMock(FulfillmentMethodCreateRequestInterface::class);
        $untyped->method('getType')
            ->willThrowException(new \InvalidArgumentException('Data for key type is not a string'));

        $shippingAddress = $this->createAddress();
        $shippingAddress->expects($this->never())->method('setCity');

        $this->processor->processFulfillmentInformation(
            $this->createQuote($shippingAddress),
            $this->createFulfillment([$untyped, $this->createShippingMethod([])])
        );
    }

    /**
     * @return void
     */
    public function testResolveBillingCountryTakesTheSubmittedDestinationCountry(): void
    {
        $shippingAddress = $this->createAddress();
        $shippingAddress->method('getCountryId')->willReturn('LV');

        $billingAddress = $this->createAddress();
        $billingAddress->method('getCountryId')->willReturn(null);
        $billingAddress->expects($this->once())->method('setCountryId')->with('LV');

        $this->processor->resolveBillingCountry($this->createQuote($shippingAddress, $billingAddress));
    }

    /**
     * No country was submitted, so none is invented; the address validator reports it as missing instead.
     *
     * @return void
     */
    public function testResolveBillingCountryLeavesBillingUnsetWhenNothingWasSubmitted(): void
    {
        $shippingAddress = $this->createAddress();
        $shippingAddress->method('getCountryId')->willReturn(null);

        $billingAddress = $this->createAddress();
        $billingAddress->method('getCountryId')->willReturn(null);
        $billingAddress->expects($this->never())->method('setCountryId');

        $this->processor->resolveBillingCountry($this->createQuote($shippingAddress, $billingAddress));
    }

    /**
     * @return void
     */
    public function testResolveBillingCountryKeepsACountryThatWasAlreadyResolved(): void
    {
        $shippingAddress = $this->createAddress();
        $shippingAddress->method('getCountryId')->willReturn('LV');

        $billingAddress = $this->createAddress();
        $billingAddress->method('getCountryId')->willReturn('DE');
        $billingAddress->expects($this->never())->method('setCountryId');

        $this->processor->resolveBillingCountry($this->createQuote($shippingAddress, $billingAddress));
    }

    /**
     * @return void
     */
    public function testProcessLineItemsHandsTheSubmittedSkusToTheSharedWriter(): void
    {
        $quote = $this->createQuote();

        $this->lineItemWriter->expects($this->once())
            ->method('write')
            ->with($quote, [['sku' => '24-MB04', 'quantity' => 1], ['sku' => 'OTHER', 'quantity' => 4]])
            ->willReturn([]);

        $this->processor->processLineItems(
            $quote,
            [$this->createLineItem('24-MB04', 1), $this->createLineItem('OTHER', 4)]
        );
    }

    /**
     * @return void
     */
    public function testProcessLineItemsReportsAnUnknownSkuInsteadOfThrowing(): void
    {
        $quote = $this->createQuote();
        $this->writerReturns([new LineItemResult(0, 'NO-SUCH-SKU', LineItemOutcome::NotFound)]);

        $this->processor->processLineItems($quote, [$this->createLineItem('NO-SUCH-SKU', 1)]);

        $this->assertCount(1, $this->createdMessages);
        $this->assertSame('error', $this->createdMessages[0]['type']);
        $this->assertSame(CheckoutDataProcessor::CODE_INVALID, $this->createdMessages[0]['code']);
        $this->assertSame('$.line_items[0].item.id', $this->createdMessages[0]['path']);
        $this->assertSame(MessageInterface::SEVERITY_RECOVERABLE, $this->createdMessages[0]['severity']);
        $this->assertStringContainsString('NO-SUCH-SKU', $this->createdMessages[0]['content']);
    }

    /**
     * @return void
     */
    public function testProcessLineItemsReportsAnUnsalableProduct(): void
    {
        $quote = $this->createQuote();
        $this->writerReturns([new LineItemResult(0, 'OOS-SKU', LineItemOutcome::NotSalable)]);

        $this->processor->processLineItems($quote, [$this->createLineItem('OOS-SKU', 1)]);

        $this->assertCount(1, $this->createdMessages);
        $this->assertSame(CheckoutDataProcessor::CODE_OUT_OF_STOCK, $this->createdMessages[0]['code']);
    }

    /**
     * A refusal carries the framework's own explanation rather than a generic message.
     *
     * @return void
     */
    public function testProcessLineItemsReportsARefusalWithItsReason(): void
    {
        $quote = $this->createQuote();
        $this->writerReturns([
            new LineItemResult(0, '24-MB04', LineItemOutcome::Rejected, 'Requested qty is not available'),
        ]);

        $this->processor->processLineItems($quote, [$this->createLineItem('24-MB04', 99)]);

        $this->assertCount(1, $this->createdMessages);
        $this->assertSame(CheckoutDataProcessor::CODE_INVALID, $this->createdMessages[0]['code']);
        $this->assertSame('Requested qty is not available', $this->createdMessages[0]['content']);
    }

    /**
     * One bad SKU must not cost the caller the rest of the cart: the added item raises no message, and
     * the failed one is reported at its own index.
     *
     * @return void
     */
    public function testProcessLineItemsKeepsAddingTheRemainingItems(): void
    {
        $quote = $this->createQuote();
        $this->writerReturns([
            new LineItemResult(0, 'NO-SUCH-SKU', LineItemOutcome::NotFound),
            new LineItemResult(1, '24-MB04', LineItemOutcome::Added),
        ]);

        $this->processor->processLineItems(
            $quote,
            [$this->createLineItem('NO-SUCH-SKU', 1), $this->createLineItem('24-MB04', 2)]
        );

        $this->assertCount(1, $this->createdMessages);
        $this->assertSame('$.line_items[0].item.id', $this->createdMessages[0]['path']);
    }

    /**
     * The URL was parsed out of the agent's profile and then dropped, so nothing was ever sent order
     * events. It is persisted with the checkout because the header is only on the agent's own requests.
     *
     * @return void
     */
    public function testTheAgentsWebhookUrlIsReadFromItsProfile(): void
    {
        $this->httpRequest->method('getHeader')->willReturn('profile="https://agent.test/.well-known/ucp"');

        $this->agentProfileParser->method('parseWebhookUrl')->willReturn('https://agent.test/hooks/orders');

        $this->assertSame('https://agent.test/hooks/orders', $this->processor->getAgentWebhookUrl());
    }

    /**
     * @return void
     */
    public function testNoAgentHeaderMeansNoWebhookUrl(): void
    {
        $this->httpRequest->method('getHeader')->willReturn(false);
        $this->agentProfileParser->expects($this->never())->method('parseWebhookUrl');

        $this->assertNull($this->processor->getAgentWebhookUrl());
    }

    /**
     * @param LineItemResult[] $results
     * @return void
     */
    private function writerReturns(array $results): void
    {
        $this->lineItemWriter->method('write')->willReturn($results);
    }

    /**
     * @return void
     */
    public function testValidateHandsTheCollectedMessagesToTheResponseBuilder(): void
    {
        $quote = $this->createQuote();
        $this->writerReturns([new LineItemResult(0, 'NO-SUCH-SKU', LineItemOutcome::NotFound)]);

        $this->assertNull($this->processor->validate($quote));

        $this->processor->processLineItems($quote, [$this->createLineItem('NO-SUCH-SKU', 1)]);

        $this->assertCount(1, $this->processor->validate($quote) ?? []);
    }

    /**
     * The phone lives under `telephone` on quote addresses and used to be dropped in transit.
     *
     * @return void
     */
    public function testCopyPersonalInformationFromBillingToShippingCopiesTheTelephone(): void
    {
        $billingAddress = $this->createAddress();
        $billingAddress->method('getFirstname')->willReturn('Ada');
        $billingAddress->method('getTelephone')->willReturn('+15555550123');

        $shippingAddress = $this->createAddress();
        $shippingAddress->expects($this->once())->method('setFirstname')->with('Ada');
        $shippingAddress->expects($this->once())->method('setTelephone')->with('+15555550123');

        $this->processor->copyPersonalInformationFromBillingToShipping(
            $this->createQuote($shippingAddress, $billingAddress)
        );
    }

    /**
     * setCollectShippingRates is a DataObject magic setter, so the mock has to be told it exists.
     *
     * @return Address&MockObject
     */
    private function createAddress(): Address
    {
        return $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'setStreet',
                'setCity',
                'setRegion',
                'setCountryId',
                'setPostcode',
                'setFirstname',
                'setLastname',
                'setTelephone',
                'setEmail',
                'getCountryId',
                'getFirstname',
                'getLastname',
                'getTelephone',
                'getEmail',
            ])
            ->addMethods(['setCollectShippingRates'])
            ->getMock();
    }

    /**
     * @param Address|null $shippingAddress
     * @param Address|null $billingAddress
     * @return Quote&MockObject
     */
    private function createQuote(?Address $shippingAddress = null, ?Address $billingAddress = null): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingAddress', 'getBillingAddress', 'getStoreId', 'removeAllItems', 'addProduct'])
            ->getMock();

        $quote->method('getShippingAddress')->willReturn($shippingAddress ?? $this->createAddress());
        $quote->method('getBillingAddress')->willReturn($billingAddress ?? $this->createAddress());
        $quote->method('getStoreId')->willReturn(self::STORE_ID);

        return $quote;
    }

    /**
     * @param string $id
     * @param array<string, string> $fields
     * @return FulfillmentDestinationRequestInterface&MockObject
     */
    private function createDestination(string $id, array $fields = []): FulfillmentDestinationRequestInterface
    {
        $defaults = [
            'street_address' => '1 Main St',
            'extended_address' => null,
            'address_locality' => 'Austin',
            'address_region' => 'TX',
            'address_country' => 'US',
            'postal_code' => '78701',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'phone_number' => '+15555550123',
        ];
        $fields = array_merge($defaults, $fields);

        $destination = $this->createMock(FulfillmentDestinationRequestInterface::class);
        $destination->method('getId')->willReturn($id);
        $destination->method('getStreetAddress')->willReturn($fields['street_address']);
        $destination->method('getExtendedAddress')->willReturn($fields['extended_address']);
        $destination->method('getAddressLocality')->willReturn($fields['address_locality']);
        $destination->method('getAddressRegion')->willReturn($fields['address_region']);
        $destination->method('getAddressCountry')->willReturn($fields['address_country']);
        $destination->method('getPostalCode')->willReturn($fields['postal_code']);
        $destination->method('getFirstName')->willReturn($fields['first_name']);
        $destination->method('getLastName')->willReturn($fields['last_name']);
        $destination->method('getPhoneNumber')->willReturn($fields['phone_number']);

        return $destination;
    }

    /**
     * @param array<FulfillmentDestinationRequestInterface> $destinations
     * @param string|null $selectedDestinationId
     * @return FulfillmentMethodCreateRequestInterface&MockObject
     */
    private function createShippingMethod(
        array $destinations,
        ?string $selectedDestinationId = null
    ): FulfillmentMethodCreateRequestInterface {
        $method = $this->createMock(FulfillmentMethodCreateRequestInterface::class);
        $method->method('getType')->willReturn(FulfillmentMethodCreateRequestInterface::TYPE_SHIPPING);
        $method->method('getDestinations')->willReturn($destinations);
        $method->method('getSelectedDestinationId')->willReturn($selectedDestinationId);
        $method->method('getGroups')->willReturn([$this->createMock(FulfillmentGroupCreateRequestInterface::class)]);

        return $method;
    }

    /**
     * @param array<FulfillmentMethodCreateRequestInterface> $methods
     * @return FulfillmentRequestInterface&MockObject
     */
    private function createFulfillment(array $methods): FulfillmentRequestInterface
    {
        $fulfillment = $this->createMock(FulfillmentRequestInterface::class);
        $fulfillment->method('getMethods')->willReturn($methods);

        return $fulfillment;
    }

    /**
     * @param string $sku
     * @param int $quantity
     * @return LineItemCreateRequestInterface&MockObject
     */
    private function createLineItem(string $sku, int $quantity): LineItemCreateRequestInterface
    {
        $item = $this->createMock(ItemCreateRequestInterface::class);
        $item->method('getId')->willReturn($sku);

        $lineItem = $this->createMock(LineItemCreateRequestInterface::class);
        $lineItem->method('getItem')->willReturn($item);
        $lineItem->method('getQuantity')->willReturn($quantity);

        return $lineItem;
    }
}
