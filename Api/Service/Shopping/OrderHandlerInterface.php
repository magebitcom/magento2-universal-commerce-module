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
     * @param string $checkoutId Session that placed the order, which is how the order is addressed
     * @return OrderResponseInterface
     * @throws LocalizedException When that session placed no order this protocol may hand out
     */
    public function getOrder(string $checkoutId): OrderResponseInterface;

    /**
     * Records the post-order adjustments the request adds and reports the order as it now stands.
     *
     * @param string $checkoutId Session that placed the order, which is how the order is addressed
     * @param OrderUpdateRequestInterface $request
     * @return OrderResponseInterface
     * @throws LocalizedException When that session placed no order this protocol may hand out
     */
    public function updateOrder(
        string $checkoutId,
        OrderUpdateRequestInterface $request
    ): OrderResponseInterface;
}
