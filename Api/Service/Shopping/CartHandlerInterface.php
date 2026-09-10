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

use Magebit\UcpSpec\Api\Shopping\CartResponseInterface;

/**
 * The cart capability's own endpoints. A cart has no complete operation: it is pre-purchase
 * exploration, and the spec gives it none.
 */
interface CartHandlerInterface
{
    /**
     * @param CheckoutCreateRequestInterface $request
     * @return CartResponseInterface
     */
    public function createCart(CheckoutCreateRequestInterface $request): CartResponseInterface;

    /**
     * @param string $cartId
     * @return CartResponseInterface
     */
    public function getCart(string $cartId): CartResponseInterface;

    /**
     * @param string $cartId
     * @param CheckoutUpdateRequestInterface $request
     * @return CartResponseInterface
     */
    public function updateCart(string $cartId, CheckoutUpdateRequestInterface $request): CartResponseInterface;

    /**
     * @param string $cartId
     * @return CartResponseInterface
     */
    public function cancelCart(string $cartId): CartResponseInterface;
}
