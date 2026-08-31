<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\UniversalCommerce\Api\Service\Shopping;

use Magebit\UcpSpec\Api\Shopping\OrderResponseInterface;
use Magebit\UcpSpec\Api\Shopping\OrderUpdateRequestInterface;
use Magento\Framework\Exception\LocalizedException;

interface OrderHandlerInterface
{
    /**
     * @param string $orderId The identifier the checkout's order confirmation advertised
     * @return OrderResponseInterface
     * @throws LocalizedException When no order of that identifier came from this protocol
     */
    public function getOrder(string $orderId): OrderResponseInterface;

    /**
     * Records the post-order adjustments the request adds and reports the order as it now stands.
     *
     * @param string $orderId The identifier the checkout's order confirmation advertised
     * @param OrderUpdateRequestInterface $request
     * @return OrderResponseInterface
     * @throws LocalizedException When no order of that identifier came from this protocol
     */
    public function updateOrder(string $orderId, OrderUpdateRequestInterface $request): OrderResponseInterface;
}
