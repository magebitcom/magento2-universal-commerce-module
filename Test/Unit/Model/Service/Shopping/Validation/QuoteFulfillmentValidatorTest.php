<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit\Model\Service\Shopping\Validation;

use Magebit\AgenticCore\Model\Fulfillment\ShippingOption;
use Magebit\AgenticCore\Model\Fulfillment\ShippingOptionResolver;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterfaceFactory;
use Magebit\UniversalCommerce\Model\Service\Shopping\Validation\QuoteFulfillmentValidator;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class QuoteFulfillmentValidatorTest extends TestCase
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $createdMessages = [];

    private ShippingOptionResolver&MockObject $shippingOptionResolver;

    private QuoteFulfillmentValidator $validator;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->createdMessages = [];

        $messageFactory = $this->createMock(MessageInterfaceFactory::class);
        $messageFactory->method('create')->willReturnCallback(function (array $arguments) {
            $this->createdMessages[] = $arguments['data'];
            return $this->createMock(MessageInterface::class);
        });

        $this->shippingOptionResolver = $this->createMock(ShippingOptionResolver::class);
        $this->validator = new QuoteFulfillmentValidator($messageFactory, $this->shippingOptionResolver);
    }

    /**
     * @return void
     */
    public function testAVirtualQuoteNeedsNoFulfillment(): void
    {
        $errors = $this->validator->validate($this->quote(true, ''));

        $this->assertSame([], $errors);
        $this->assertSame([], $this->createdMessages);
    }

    /**
     * @return void
     */
    public function testAMissingSelectionIsReportedAgainstTheFulfillmentTree(): void
    {
        $this->shippingOptionResolver->expects($this->never())->method('resolve');

        $errors = $this->validator->validate($this->quote(false, ''));

        $this->assertCount(1, $errors);
        $this->assertSame('missing', $this->createdMessages[0]['code']);
        $this->assertSame('$.fulfillment', $this->createdMessages[0]['path']);
        $this->assertStringContainsString('fulfillment', $this->createdMessages[0]['content']);
    }

    /**
     * @return void
     */
    public function testAnOfferedSelectionPasses(): void
    {
        $this->shippingOptionResolver->method('resolve')->willReturn([$this->option('flatrate_flatrate')]);

        $this->assertSame([], $this->validator->validate($this->quote(false, 'flatrate_flatrate')));
    }

    /**
     * @return void
     */
    public function testASelectionTheStoreDoesNotOfferIsReported(): void
    {
        $this->shippingOptionResolver->method('resolve')->willReturn([$this->option('flatrate_flatrate')]);

        $errors = $this->validator->validate($this->quote(false, 'std-ship'));

        $this->assertCount(1, $errors);
        $this->assertSame('invalid', $this->createdMessages[0]['code']);
        $this->assertSame(
            '$.fulfillment.methods[0].groups[0].selected_option_id',
            $this->createdMessages[0]['path']
        );
        $this->assertStringContainsString('std-ship', $this->createdMessages[0]['content']);
    }

    /**
     * With no rates at all the destination is what needs fixing, and the address validator says so.
     *
     * @return void
     */
    public function testWithNoOptionsAtAllTheSelectionIsNotSecondGuessed(): void
    {
        $this->shippingOptionResolver->method('resolve')->willReturn([]);

        $this->assertSame([], $this->validator->validate($this->quote(false, 'std-ship')));
    }

    /**
     * @param string $id
     * @return ShippingOption
     */
    private function option(string $id): ShippingOption
    {
        return new ShippingOption($id, 'Fixed', null, 'Flat Rate', 500, 0, 500);
    }

    /**
     * @param bool $isVirtual
     * @param string $shippingMethod
     * @return Quote&MockObject
     */
    private function quote(bool $isVirtual, string $shippingMethod): Quote&MockObject
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingMethod'])
            ->getMock();
        $address->method('getShippingMethod')->willReturn($shippingMethod);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getIsVirtual', 'getShippingAddress'])
            ->getMock();
        $quote->method('getIsVirtual')->willReturn($isVirtual);
        $quote->method('getShippingAddress')->willReturn($address);

        return $quote;
    }
}
