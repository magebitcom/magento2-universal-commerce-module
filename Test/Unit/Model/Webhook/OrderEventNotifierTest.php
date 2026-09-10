<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit\Model\Webhook;

use Magebit\AgenticCore\Api\OrderLinkRepositoryInterface;
use Magebit\AgenticCore\Model\Webhook\Dispatcher;
use Magebit\UcpSpec\Api\Shopping\OrderResponseInterface;
use Magebit\UniversalCommerce\Api\CheckoutMetaRepositoryInterface;
use Magebit\UniversalCommerce\Api\Data\CheckoutMetaInterface;
use Magebit\UniversalCommerce\Model\Config;
use Magebit\UniversalCommerce\Model\IdempotencyHandler;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderToOrderResponse;
use Magebit\UniversalCommerce\Model\Webhook\OrderEventNotifier;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrderEventNotifierTest extends TestCase
{
    private const ENTITY_ID = 42;
    private const CHECKOUT_ID = 'checkout_session_placeholder_0001';
    private const AGENT_URL = 'https://agent.test/hook';

    /** @var Dispatcher&MockObject */
    private Dispatcher $dispatcher;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->dispatcher = $this->createMock(Dispatcher::class);
    }

    /**
     * @return void
     */
    public function testTheOrderIsQueuedToTheAgentsOwnUrl(): void
    {
        $this->dispatcher->expects($this->once())
            ->method('enqueue')
            ->with(IdempotencyHandler::SCOPE, self::AGENT_URL, $this->isType('string'), $this->isType('string'));

        $this->notifier()->notify($this->order());
    }

    /**
     * Delivery ships disabled because no credential scheme has been chosen, and a disabled merchant must
     * not accumulate rows nobody will send.
     *
     * @return void
     */
    public function testNothingIsQueuedWhileDeliveryIsDisabled(): void
    {
        $this->dispatcher->expects($this->never())->method('enqueue');

        $this->notifier(enabled: false)->notify($this->order());
    }

    /**
     * @return void
     */
    public function testAnOrderFromNoSessionOfThisProtocolIsIgnored(): void
    {
        $this->dispatcher->expects($this->never())->method('enqueue');

        $this->notifier(checkoutId: null)->notify($this->order());
    }

    /**
     * The URL comes from the agent's own profile, and most profiles declare none.
     *
     * @return void
     */
    public function testAnAgentThatDeclaredNoUrlIsNotCalled(): void
    {
        $this->dispatcher->expects($this->never())->method('enqueue');

        $this->notifier(url: null)->notify($this->order());
    }

    /**
     * @return void
     */
    public function testAMissingCheckoutRecordIsNotAnError(): void
    {
        $this->dispatcher->expects($this->never())->method('enqueue');

        $this->notifier(metaMissing: true)->notify($this->order());
    }

    /**
     * Each event gets its own identifier: it becomes Webhook-Id, which names the event rather than the
     * order or the session, and has to stay put across retries of that one event.
     *
     * @return void
     */
    public function testEachEventCarriesItsOwnIdentifier(): void
    {
        $references = [];

        $this->dispatcher->method('enqueue')->willReturnCallback(
            function (string $scope, string $url, string $payload, string $reference) use (&$references): void {
                $references[] = $reference;
            }
        );

        $notifier = $this->notifier();
        $notifier->notify($this->order());
        $notifier->notify($this->order());

        $this->assertCount(2, $references);
        $this->assertNotSame($references[0], $references[1]);
    }

    /**
     * The order has already happened by the time this runs, so a queue failure is logged rather than
     * thrown back into the transaction that placed it.
     *
     * @return void
     */
    public function testAQueueFailureDoesNotEscape(): void
    {
        $this->dispatcher->method('enqueue')->willThrowException(new CouldNotSaveException(__('nope')));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('critical');

        $this->notifier(logger: $logger)->notify($this->order());
    }

    /**
     * @param bool $enabled
     * @param string|null $checkoutId
     * @param string|null $url
     * @param bool $metaMissing
     * @param LoggerInterface|null $logger
     * @return OrderEventNotifier
     */
    private function notifier(
        bool $enabled = true,
        ?string $checkoutId = self::CHECKOUT_ID,
        ?string $url = self::AGENT_URL,
        bool $metaMissing = false,
        ?LoggerInterface $logger = null
    ): OrderEventNotifier {
        $links = $this->createMock(OrderLinkRepositoryInterface::class);
        $links->method('findSessionId')->willReturn($checkoutId);

        $meta = $this->createMock(CheckoutMetaInterface::class);
        $meta->method('getWebhookUrl')->willReturn($url);

        $metaRepository = $this->createMock(CheckoutMetaRepositoryInterface::class);

        if ($metaMissing) {
            $metaRepository->method('getByCheckoutId')->willThrowException(new NoSuchEntityException(__('gone')));
        } else {
            $metaRepository->method('getByCheckoutId')->willReturn($meta);
        }

        $converter = $this->createMock(OrderToOrderResponse::class);
        $converter->method('convert')->willReturn($this->createMock(OrderResponseInterface::class));

        $identity = $this->createMock(IdentityGeneratorInterface::class);
        $sequence = 0;
        $identity->method('generateId')->willReturnCallback(function () use (&$sequence): string {
            return sprintf('11111111-2222-4333-8444-%012d', ++$sequence);
        });

        $config = $this->createMock(Config::class);
        $config->method('areWebhooksEnabled')->willReturn($enabled);

        return new OrderEventNotifier(
            $this->dispatcher,
            $links,
            $metaRepository,
            $converter,
            $identity,
            $config,
            $logger ?? $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @return Order
     */
    private function order(): Order
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEntityId', 'getStoreId', 'getIncrementId'])
            ->getMock();
        $order->method('getEntityId')->willReturn(self::ENTITY_ID);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('000000123');

        return $order;
    }
}
