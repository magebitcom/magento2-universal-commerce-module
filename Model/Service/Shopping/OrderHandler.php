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
use Magebit\UniversalCommerce\Api\Service\Shopping\OrderHandlerInterface;
use Magebit\UniversalCommerce\Exception\UcpException;
use Magebit\UniversalCommerce\Model\IdempotencyHandler;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderToOrderResponse;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

class OrderHandler implements OrderHandlerInterface
{
    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param OrderLinkRepositoryInterface $orderLinkRepository
     * @param OrderToOrderResponse $orderConverter
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly OrderLinkRepositoryInterface $orderLinkRepository,
        private readonly OrderToOrderResponse $orderConverter
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getOrder(string $orderId): OrderResponseInterface
    {
        $order = $this->findByIncrementId($orderId);
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

        return $this->orderConverter->convert($order, $checkoutId);
    }

    /**
     * The identifier is the increment id, because that is what the checkout's order confirmation gave
     * the agent.
     *
     * @param string $incrementId
     * @return Order|null
     */
    private function findByIncrementId(string $incrementId): ?Order
    {
        if ($incrementId === '') {
            return null;
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter(OrderInterface::INCREMENT_ID, $incrementId)
            ->setPageSize(1)
            ->create();

        foreach ($this->orderRepository->getList($criteria)->getItems() as $order) {
            if ($order instanceof Order) {
                return $order;
            }
        }

        return null;
    }
}
