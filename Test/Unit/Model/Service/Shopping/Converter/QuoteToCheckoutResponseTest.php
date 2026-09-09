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

use Magebit\AgenticCore\Api\OrderLinkRepositoryInterface;
use Magebit\UcpSpec\Api\Shopping\Types\OrderConfirmationInterface;
use Magebit\UcpSpec\Api\Shopping\Types\OrderConfirmationInterfaceFactory;
use Magebit\UcpSpec\Data\Shopping\Types\OrderConfirmation;
use Magebit\UniversalCommerce\Model\Config;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\QuoteToCheckoutResponse;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class QuoteToCheckoutResponseTest extends TestCase
{
    private const CHECKOUT_ID = 'session-abc';
    private const INCREMENT_ID = '000000123';
    private const ORDER_ID = 7;

    /**
     * The confirmation answers to the checkout session, the same identifier the order endpoint takes.
     * The store's own order number runs in sequence, so anyone could count up to someone else's; it
     * is only a label. Built without the constructor so the test does not stand up seventeen
     * collaborators, then given the four the confirmation is made from.
     *
     * @return void
     */
    public function testConfirmationIsAddressedByCheckoutSessionAndLabelledByOrderNumber(): void
    {
        $confirmation = $this->confirmationFor(self::CHECKOUT_ID);

        $this->assertInstanceOf(OrderConfirmationInterface::class, $confirmation);
        $this->assertSame(self::CHECKOUT_ID, $confirmation->getId());
        $this->assertSame(self::INCREMENT_ID, $confirmation->getLabel());
    }

    /**
     * A session with no order behind it has nothing to confirm.
     *
     * @return void
     */
    public function testSessionWithNoOrderHasNoConfirmation(): void
    {
        $this->assertNull($this->confirmationFor('session-without-order'));
    }

    /**
     * @param string $checkoutId Session to build the confirmation for
     * @return OrderConfirmationInterface|null
     */
    private function confirmationFor(string $checkoutId): ?OrderConfirmationInterface
    {
        $reflection = new ReflectionClass(QuoteToCheckoutResponse::class);
        $converter = $reflection->newInstanceWithoutConstructor();

        $order = $this->createMock(OrderInterface::class);
        $order->method('getIncrementId')->willReturn(self::INCREMENT_ID);
        $order->method('getStoreId')->willReturn(1);

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('get')->willReturn($order);

        $orderLinkRepository = $this->createMock(OrderLinkRepositoryInterface::class);
        $orderLinkRepository->method('findOrderId')->willReturnCallback(
            static fn (string $scope, string $key): ?int => $key === self::CHECKOUT_ID ? self::ORDER_ID : null
        );

        $confirmationFactory = $this->createMock(OrderConfirmationInterfaceFactory::class);
        $confirmationFactory->method('create')->willReturnCallback(
            static fn (): OrderConfirmation => new OrderConfirmation()
        );

        $this->give($reflection, $converter, [
            'orderLinkRepository' => $orderLinkRepository,
            'orderRepository' => $orderRepository,
            'orderConfirmationFactory' => $confirmationFactory,
            'config' => $this->createMock(Config::class),
        ]);

        $method = $reflection->getMethod('getOrder');
        $method->setAccessible(true);

        return $method->invoke($converter, $checkoutId);
    }

    /**
     * @param ReflectionClass<QuoteToCheckoutResponse> $reflection
     * @param QuoteToCheckoutResponse $converter
     * @param array<string, object> $collaborators Property name to the mock it takes
     * @return void
     */
    private function give(ReflectionClass $reflection, QuoteToCheckoutResponse $converter, array $collaborators): void
    {
        foreach ($collaborators as $name => $collaborator) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($converter, $collaborator);
        }
    }
}
