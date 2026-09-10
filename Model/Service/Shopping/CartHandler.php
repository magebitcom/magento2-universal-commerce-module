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

use Magebit\UniversalCommerce\Api\Service\Shopping\CartHandlerInterface;
use Magebit\UniversalCommerce\Api\Service\Shopping\CheckoutCreateRequestInterface;
use Magebit\UcpSpec\Api\Shopping\CartResponseInterface;
use Magebit\UniversalCommerce\Api\Service\Shopping\CheckoutUpdateRequestInterface;
use Magebit\UniversalCommerce\Api\Service\Shopping\RestHandlerInterface;
use Magebit\UniversalCommerce\Exception\UcpException;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\QuoteToCartResponse;
use Magento\Quote\Api\Data\CartInterface;

/**
 * A cart is the same quote a checkout is held on, minus the payment surface and the status lifecycle.
 */
class CartHandler implements CartHandlerInterface
{
    /**
     * @param RestHandlerInterface $restHandler
     * @param QuoteToCartResponse $cartConverter
     */
    public function __construct(
        private readonly RestHandlerInterface $restHandler,
        private readonly QuoteToCartResponse $cartConverter
    ) {
    }

    /**
     * @inheritDoc
     */
    public function createCart(CheckoutCreateRequestInterface $request): CartResponseInterface
    {
        $created = $this->restHandler->createCheckout($request);

        return $this->convert((string) $created->getId());
    }

    /**
     * @inheritDoc
     */
    public function getCart(string $cartId): CartResponseInterface
    {
        return $this->convert($cartId, true);
    }

    /**
     * @inheritDoc
     */
    public function updateCart(string $cartId, CheckoutUpdateRequestInterface $request): CartResponseInterface
    {
        $this->assertExists($cartId);
        $this->restHandler->updateCheckout($cartId, $request);

        return $this->convert($cartId);
    }

    /**
     * The spec has cancel return the state before deletion, so the response is built from the cart as it
     * stood and only later reads report it gone.
     *
     * @inheritDoc
     */
    public function cancelCart(string $cartId): CartResponseInterface
    {
        $this->assertExists($cartId);
        $this->restHandler->cancelCheckout($cartId);

        return $this->convert($cartId);
    }

    /**
     * @param string $cartId
     * @param bool $mustBeLive Reads report a canceled cart as gone; the operation that canceled it does not
     * @return CartResponseInterface
     * @throws UcpException
     */
    private function convert(string $cartId, bool $mustBeLive = false): CartResponseInterface
    {
        $quote = $this->restHandler->getCartByMaskedId($cartId);

        if ($mustBeLive) {
            $this->assertLive($quote, $cartId);
        }

        return $this->cartConverter->convert($quote, $cartId);
    }

    /**
     * @param string $cartId
     * @return void
     * @throws UcpException
     */
    private function assertExists(string $cartId): void
    {
        $this->assertLive($this->restHandler->getCartByMaskedId($cartId), $cartId);
    }

    /**
     * A cart's status is binary: it exists, or it is not found. A canceled cart is gone, so it is
     * reported as missing rather than as a cart in a canceled state.
     *
     * @param CartInterface $quote
     * @param string $cartId
     * @return void
     * @throws UcpException
     */
    private function assertLive(CartInterface $quote, string $cartId): void
    {
        if (!$quote->getIsActive()) {
            throw new UcpException(__('Cart not found: %1.', $cartId), 'not_found', 404);
        }
    }
}
