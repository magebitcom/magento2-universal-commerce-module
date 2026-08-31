<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\Service\Shopping;

use Magebit\AgenticCore\Api\OrderLinkRepositoryInterface;
use Magebit\UcpSpec\Api\Shopping\OrderResponseInterface;
use Magebit\UcpSpec\Api\Shopping\OrderUpdateRequestInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterfaceFactory;
use Magebit\UniversalCommerce\Api\Service\Shopping\OrderHandlerInterface;
use Magebit\UniversalCommerce\Exception\UcpException;
use Magebit\UniversalCommerce\Model\IdempotencyHandler;
use Magebit\UniversalCommerce\Model\Order\AdjustmentRecorder;
use Magebit\UniversalCommerce\Model\Order\IncrementIdLookup;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderToOrderResponse;
use Magento\Sales\Model\Order;

class OrderHandler implements OrderHandlerInterface
{
    /**
     * Freeform, because the specification's own list has no code for a field the business does not take
     * from the platform.
     */
    public const CODE_NOT_ACCEPTED = 'not_accepted';

    /**
     * @param IncrementIdLookup $orderLookup
     * @param OrderLinkRepositoryInterface $orderLinkRepository
     * @param OrderToOrderResponse $orderConverter
     * @param AdjustmentRecorder $adjustmentRecorder
     * @param MessageInterfaceFactory $messageFactory
     */
    public function __construct(
        private readonly IncrementIdLookup $orderLookup,
        private readonly OrderLinkRepositoryInterface $orderLinkRepository,
        private readonly OrderToOrderResponse $orderConverter,
        private readonly AdjustmentRecorder $adjustmentRecorder,
        private readonly MessageInterfaceFactory $messageFactory
    ) {
    }


    /**
     * @inheritDoc
     */
    public function getOrder(string $orderId): OrderResponseInterface
    {
        [$order, $checkoutId] = $this->resolve($orderId);

        return $this->orderConverter->convert($order, $checkoutId);
    }

    /**
     * Only the adjustments are taken from the request. Everything else it carries — line items, totals,
     * the permalink — describes the order, which is the store's to state and not the agent's to change.
     *
     * @inheritDoc
     */
    public function updateOrder(string $orderId, OrderUpdateRequestInterface $request): OrderResponseInterface
    {
        [$order, $checkoutId] = $this->resolve($orderId);

        $this->adjustmentRecorder->record($order, $request->getAdjustments() ?? []);

        $response = $this->orderConverter->convert($order, $checkoutId);
        $warning = $this->refusedShipmentWarning($request, $response);

        if ($warning !== null) {
            $response->setMessages([$warning]);
        }

        return $response;
    }

    /**
     * The shipment log says what the store actually did, so it is not the platform's to write. A
     * submitted event is left out and said so, rather than being dropped without a word.
     *
     * @param OrderUpdateRequestInterface $request
     * @param OrderResponseInterface $response
     * @return MessageInterface|null
     */
    private function refusedShipmentWarning(
        OrderUpdateRequestInterface $request,
        OrderResponseInterface $response
    ): ?MessageInterface {
        $submitted = $request->getFulfillment()->getEvents() ?? [];
        $recorded = $response->getFulfillment()->getEvents() ?? [];

        if (count($submitted) <= count($recorded)) {
            return null;
        }

        /** @var MessageInterface $warning */
        $warning = $this->messageFactory->create(['data' => [
            MessageInterface::KEY_TYPE => 'warning',
            MessageInterface::KEY_CODE => self::CODE_NOT_ACCEPTED,
            MessageInterface::KEY_PATH => '$.fulfillment.events',
            MessageInterface::KEY_CONTENT => 'This store records shipments itself, so the fulfillment '
                . 'events in this request were not added. The events reported here are the ones the '
                . 'store has shipped.',
        ]]);

        return $warning;
    }

    /**
     * @param string $orderId
     * @return array{Order, string} The order and the session it was placed from
     * @throws UcpException When no order of that identifier came from this protocol
     */
    private function resolve(string $orderId): array
    {
        $order = $this->orderLookup->find($orderId);
        $entityId = $order?->getEntityId();
        $checkoutId = is_numeric($entityId)
            ? $this->orderLinkRepository->findSessionId(IdempotencyHandler::SCOPE, (int) $entityId)
            : null;

        // The spec has the business verify that the caller's own checkout produced this order. Until
        // callers are authenticated, the link is what can be checked: an order placed through the
        // storefront, or through the other protocol, is not this protocol's to hand out.
        if ($order === null || $checkoutId === null) {
            throw new UcpException(
                __('Order not found: %1.', $orderId),
                'not_found',
                404
            );
        }

        return [$order, $checkoutId];
    }
}
