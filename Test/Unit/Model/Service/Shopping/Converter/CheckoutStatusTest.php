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

use Magebit\AgenticCore\Model\Checkout\StateResolver;
use Magebit\UniversalCommerce\Api\Service\Shopping\CheckoutResponseInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterface;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\QuoteToCheckoutResponse;
use Magento\Quote\Api\Data\CartInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class CheckoutStatusTest extends TestCase
{
    /** @var QuoteToCheckoutResponse */
    private QuoteToCheckoutResponse $converter;

    /**
     * The status rule is pure; instantiate without the constructor so the test does not have to stand
     * up eleven collaborators, then supply only the resolver it delegates to. What is asserted here is
     * the mapping onto this protocol's vocabulary — the resolution itself is covered in the base module.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $reflection = new ReflectionClass(QuoteToCheckoutResponse::class);
        $this->converter = $reflection->newInstanceWithoutConstructor();

        $property = $reflection->getProperty('stateResolver');
        $property->setAccessible(true);
        $property->setValue($this->converter, new StateResolver());
    }

    /**
     * Placing an order deactivates the quote, so without this an order would report `canceled`.
     *
     * @return void
     */
    public function testOrderWinsOverInactiveQuote(): void
    {
        $this->assertSame(
            CheckoutResponseInterface::STATUS_COMPLETED,
            $this->converter->getStatus($this->quote(false), [], true)
        );
    }

    /**
     * @return void
     */
    public function testOrderWinsOverValidationErrors(): void
    {
        $this->assertSame(
            CheckoutResponseInterface::STATUS_COMPLETED,
            $this->converter->getStatus($this->quote(true), [$this->message()], true)
        );
    }

    /**
     * @return void
     */
    public function testInactiveQuoteWithoutOrderIsCanceled(): void
    {
        $this->assertSame(
            CheckoutResponseInterface::STATUS_CANCELED,
            $this->converter->getStatus($this->quote(false), [], false)
        );
    }

    /**
     * @return void
     */
    public function testValidationErrorsMakeItIncomplete(): void
    {
        $this->assertSame(
            CheckoutResponseInterface::STATUS_INCOMPLETE,
            $this->converter->getStatus($this->quote(true), [$this->message()], false)
        );
    }

    /**
     * @return void
     */
    public function testCleanActiveQuoteIsReadyForComplete(): void
    {
        $this->assertSame(
            CheckoutResponseInterface::STATUS_READY_FOR_COMPLETE,
            $this->converter->getStatus($this->quote(true), [], false)
        );
    }

    /**
     * @return void
     */
    public function testOrderDefaultsToAbsent(): void
    {
        $this->assertSame(
            CheckoutResponseInterface::STATUS_CANCELED,
            $this->converter->getStatus($this->quote(false), [])
        );
    }

    /**
     * @param bool $isActive
     * @return CartInterface&MockObject
     */
    private function quote(bool $isActive): CartInterface
    {
        $quote = $this->createMock(CartInterface::class);
        $quote->method('getIsActive')->willReturn($isActive);

        return $quote;
    }

    /**
     * @return MessageInterface&MockObject
     */
    private function message(): MessageInterface
    {
        return $this->createMock(MessageInterface::class);
    }
}
