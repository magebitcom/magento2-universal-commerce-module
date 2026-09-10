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

use Magebit\UcpSpec\Api\Shopping\OrderResponseInterface;
use Magebit\UcpSpec\Api\Shopping\OrderUpdateRequestInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterfaceFactory;
use Magebit\UniversalCommerce\Api\Service\Shopping\OrderHandlerInterface;
use Magebit\UniversalCommerce\Exception\UcpException;
use Magebit\UniversalCommerce\Model\Order\AdjustmentRecorder;
use Magebit\UniversalCommerce\Model\Order\SessionOrderLookup;
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
     * @param SessionOrderLookup $orderLookup
     * @param OrderToOrderResponse $orderConverter
     * @param AdjustmentRecorder $adjustmentRecorder
     * @param MessageInterfaceFactory $messageFactory
     */
    public function __construct(
        private readonly SessionOrderLookup $orderLookup,
        private readonly OrderToOrderResponse $orderConverter,
        private readonly AdjustmentRecorder $adjustmentRecorder,
        private readonly MessageInterfaceFactory $messageFactory
    ) {
    }


    /**
     * @inheritDoc
     */
    public function getOrder(string $checkoutId): OrderResponseInterface
    {
        return $this->orderConverter->convert($this->resolve($checkoutId), $checkoutId);
    }

    /**
     * Only the adjustments are taken from the request. Everything else it carries — line items, totals,
     * the permalink — describes the order, which is the store's to state and not the agent's to change.
     *
     * @inheritDoc
     */
    public function updateOrder(string $checkoutId, OrderUpdateRequestInterface $request): OrderResponseInterface
    {
        $order = $this->resolve($checkoutId);

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
     * Orders are reached only through the checkout session that placed them. The session id cannot be
     * guessed, while the store's own order number runs in sequence and anyone could count up to it.
     *
     * @param string $checkoutId
     * @return Order
     * @throws UcpException When that session placed no order this protocol may hand out
     */
    private function resolve(string $checkoutId): Order
    {
        $order = $this->orderLookup->find($checkoutId);

        if ($order === null) {
            throw new UcpException(
                __('Order not found: %1.', $checkoutId),
                'not_found',
                404
            );
        }

        return $order;
    }
}
