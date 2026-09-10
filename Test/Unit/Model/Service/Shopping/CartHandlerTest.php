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

use Magebit\UniversalCommerce\Api\Service\Shopping\CheckoutCreateRequestInterface;
use Magebit\UniversalCommerce\Api\Service\Shopping\CheckoutUpdateRequestInterface;
use Magebit\UniversalCommerce\Api\Service\Shopping\RestHandlerInterface;
use Magebit\UniversalCommerce\Exception\UcpException;
use Magebit\UniversalCommerce\Model\Service\Shopping\CartHandler;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\QuoteToCartResponse;
use Magebit\UcpSpec\Data\Shopping\CartResponse;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CartHandlerTest extends TestCase
{
    private const CART_ID = 'cart_placeholder_0001';

    /**
     * @var RestHandlerInterface&MockObject
     */
    private RestHandlerInterface $restHandler;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->restHandler = $this->createMock(RestHandlerInterface::class);
    }

    /**
     * @return void
     */
    public function testCreateReturnsTheNewCart(): void
    {
        $this->restHandler->method('createCheckout')
            ->willReturn(new \Magebit\UniversalCommerce\Model\Spec\Schemas\Shopping\CheckoutResponse(
                ['id' => self::CART_ID]
            ));

        $created = $this->handler()->createCart($this->createMock(CheckoutCreateRequestInterface::class));

        $this->assertSame(self::CART_ID, $created->getId());
    }

    /**
     * @return void
     */
    public function testGetReturnsTheCart(): void
    {
        $this->assertSame(self::CART_ID, $this->handler()->getCart(self::CART_ID)->getId());
    }

    /**
     * A cart's status is binary — it exists or it is not found — so a canceled cart is reported gone
     * rather than as a cart in a canceled state.
     *
     * @return void
     */
    public function testReadingACanceledCartIsNotFound(): void
    {
        $handler = $this->handler(isActive: false);

        $this->expectException(UcpException::class);
        $this->expectExceptionMessage('Cart not found');

        $handler->getCart(self::CART_ID);
    }

    /**
     * @return void
     */
    public function testTheNotFoundStatusCodeIs404(): void
    {
        try {
            $this->handler(isActive: false)->getCart(self::CART_ID);
            $this->fail('Expected a not-found failure.');
        } catch (UcpException $exception) {
            $this->assertSame(404, $exception->statusCode);
            $this->assertSame('not_found', $exception->errorCode);
        }
    }

    /**
     * @return void
     */
    public function testACanceledCartCannotBeUpdated(): void
    {
        $this->restHandler->expects($this->never())->method('updateCheckout');

        $this->expectException(UcpException::class);

        $this->handler(isActive: false)->updateCart(
            self::CART_ID,
            $this->createMock(CheckoutUpdateRequestInterface::class)
        );
    }

    /**
     * @return void
     */
    public function testACanceledCartCannotBeCanceledAgain(): void
    {
        $this->restHandler->expects($this->never())->method('cancelCheckout');

        $this->expectException(UcpException::class);

        $this->handler(isActive: false)->cancelCart(self::CART_ID);
    }

    /**
     * The spec has cancel return the state before deletion, so the cancelling call still answers with a
     * body even though later reads report the cart gone.
     *
     * @return void
     */
    public function testCancelStillReturnsTheCart(): void
    {
        $this->restHandler->expects($this->once())->method('cancelCheckout');

        $this->assertSame(self::CART_ID, $this->handler()->cancelCart(self::CART_ID)->getId());
    }

    /**
     * A cart has no complete operation at all.
     *
     * @return void
     */
    public function testACartIsNeverCompleted(): void
    {
        $this->restHandler->expects($this->never())->method('completeCheckout');

        $this->handler()->getCart(self::CART_ID);
    }

    /**
     * @param bool $isActive
     * @return CartHandler
     */
    private function handler(bool $isActive = true): CartHandler
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getIsActive'])
            ->getMock();
        $quote->method('getIsActive')->willReturn($isActive);

        $this->restHandler->method('getCartByMaskedId')->willReturn($quote);

        $converter = $this->createMock(QuoteToCartResponse::class);
        $converter->method('convert')->willReturnCallback(
            static fn (mixed $q, string $cartId): CartResponse => new CartResponse(['id' => $cartId])
        );

        return new CartHandler($this->restHandler, $converter);
    }
}
