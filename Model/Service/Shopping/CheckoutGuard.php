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
use Magebit\AgenticCore\Model\Checkout\CheckoutState;
use Magebit\AgenticCore\Model\Checkout\StateResolver;
use Magebit\UniversalCommerce\Exception\UcpException;
use Magebit\UniversalCommerce\Model\IdempotencyHandler;
use Magento\Quote\Api\Data\CartInterface;

/**
 * Refuses changes to a checkout that has already finished, so an agent is told the session is closed
 * rather than being handed a success it cannot act on.
 */
class CheckoutGuard
{
    public const CODE_COMPLETED = 'checkout_completed';

    public const CODE_CANCELED = 'checkout_canceled';

    /**
     * @param StateResolver $stateResolver
     * @param OrderLinkRepositoryInterface $orderLinkRepository
     */
    public function __construct(
        private readonly StateResolver $stateResolver,
        private readonly OrderLinkRepositoryInterface $orderLinkRepository
    ) {
    }

    /**
     * @param CartInterface $quote
     * @param string $checkoutId
     * @return void
     * @throws UcpException When the session has already been completed or canceled
     */
    public function assertOpen(CartInterface $quote, string $checkoutId): void
    {
        if ($this->isCompleted($quote, $checkoutId)) {
            throw new UcpException(
                __('Checkout session %1 is already completed and can no longer be changed.', $checkoutId),
                self::CODE_COMPLETED,
                409
            );
        }

        if ($this->stateResolver->resolve($quote, false, false) === CheckoutState::Canceled) {
            throw new UcpException(
                __('Checkout session %1 is canceled and can no longer be changed.', $checkoutId),
                self::CODE_CANCELED,
                409
            );
        }
    }

    /**
     * @param CartInterface $quote
     * @param string $checkoutId
     * @return bool
     */
    public function isCompleted(CartInterface $quote, string $checkoutId): bool
    {
        $hasOrder = $this->orderLinkRepository->findOrderId(IdempotencyHandler::SCOPE, $checkoutId) !== null;

        return $this->stateResolver->resolve($quote, $hasOrder, false) === CheckoutState::Completed;
    }
}
