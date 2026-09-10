<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\Order;

use Magebit\AgenticCore\Api\OrderLinkRepositoryInterface;
use Magebit\UniversalCommerce\Model\IdempotencyHandler;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * Finds the order a checkout session placed. That session is the identifier the protocol hands out,
 * because it cannot be guessed, unlike the store's own order number which runs in sequence.
 */
class SessionOrderLookup
{
    /**
     * @param OrderLinkRepositoryInterface $orderLinkRepository
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        private readonly OrderLinkRepositoryInterface $orderLinkRepository,
        private readonly OrderRepositoryInterface $orderRepository
    ) {
    }

    /**
     * @param string $checkoutId
     * @return Order|null Null when no session of this protocol placed an order under this identifier
     */
    public function find(string $checkoutId): ?Order
    {
        if ($checkoutId === '') {
            return null;
        }

        $orderId = $this->orderLinkRepository->findOrderId(IdempotencyHandler::SCOPE, $checkoutId);

        if ($orderId === null) {
            return null;
        }

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException $exception) {
            return null;
        }

        return $order instanceof Order ? $order : null;
    }
}
