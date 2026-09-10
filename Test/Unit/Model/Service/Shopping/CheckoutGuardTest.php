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

use Magebit\AgenticCore\Api\OrderLinkRepositoryInterface;
use Magebit\AgenticCore\Model\Checkout\StateResolver;
use Magebit\UniversalCommerce\Exception\UcpException;
use Magebit\UniversalCommerce\Model\Service\Shopping\CheckoutGuard;
use Magento\Quote\Api\Data\CartInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CheckoutGuardTest extends TestCase
{
    private const CHECKOUT_ID = 'masked-checkout-id';

    private OrderLinkRepositoryInterface&MockObject $orderLinkRepository;

    private CheckoutGuard $guard;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->orderLinkRepository = $this->createMock(OrderLinkRepositoryInterface::class);
        $this->guard = new CheckoutGuard(new StateResolver(), $this->orderLinkRepository);
    }

    /**
     * @return void
     */
    public function testAnOpenCheckoutIsLetThrough(): void
    {
        $this->expectNotToPerformAssertions();
        $this->orderLinkRepository->method('findOrderId')->willReturn(null);

        $this->guard->assertOpen($this->quote(true), self::CHECKOUT_ID);
    }

    /**
     * @return void
     */
    public function testACanceledCheckoutIsRefusedAsAConflict(): void
    {
        $this->orderLinkRepository->method('findOrderId')->willReturn(null);

        try {
            $this->guard->assertOpen($this->quote(false), self::CHECKOUT_ID);
            $this->fail('A canceled checkout must not accept changes.');
        } catch (UcpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame(CheckoutGuard::CODE_CANCELED, $exception->getErrorCode());
        }
    }

    /**
     * Placing an order deactivates the quote, so a completed checkout would otherwise report itself
     * canceled and send the agent looking for the wrong thing.
     *
     * @return void
     */
    public function testACompletedCheckoutIsRefusedAsCompletedRatherThanCanceled(): void
    {
        $this->orderLinkRepository->method('findOrderId')->willReturn(42);

        try {
            $this->guard->assertOpen($this->quote(false), self::CHECKOUT_ID);
            $this->fail('A completed checkout must not accept changes.');
        } catch (UcpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame(CheckoutGuard::CODE_COMPLETED, $exception->getErrorCode());
        }
    }

    /**
     * @return void
     */
    public function testCompletionIsRecognisedFromTheOrderLink(): void
    {
        $this->orderLinkRepository->method('findOrderId')->willReturn(42);

        $this->assertTrue($this->guard->isCompleted($this->quote(false), self::CHECKOUT_ID));
    }

    /**
     * @param bool $isActive
     * @return CartInterface&MockObject
     */
    private function quote(bool $isActive): CartInterface&MockObject
    {
        $quote = $this->createMock(CartInterface::class);
        $quote->method('getIsActive')->willReturn($isActive);

        return $quote;
    }
}
