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

use Magebit\UniversalCommerce\Api\Service\Shopping\CheckoutCreateRequestInterface;
use Magebit\UniversalCommerce\Api\Service\Shopping\CheckoutResponseInterface;
use Magebit\UcpSpec\Api\Shopping\PaymentInterface;

interface RestHandlerInterface
{
    /**
     * @param CheckoutCreateRequestInterface $request
     * @return CheckoutResponseInterface
     */
    public function createCheckout(CheckoutCreateRequestInterface $request): CheckoutResponseInterface;

    /**
     * @param string $checkoutId
     * @return CheckoutResponseInterface
     */
    public function getCheckout(string $checkoutId): CheckoutResponseInterface;

    /**
     * @param string $checkoutId
     * @return CheckoutResponseInterface
     */
    public function cancelCheckout(string $checkoutId): CheckoutResponseInterface;

    /**
     * @param string $checkoutId
     * @param CheckoutUpdateRequestInterface $request
     * @return CheckoutResponseInterface
     */
    public function updateCheckout(string $checkoutId, CheckoutUpdateRequestInterface $request): CheckoutResponseInterface;

    /**
     * @param string $checkoutId
     * @param PaymentInterface $paymentData
     * @return CheckoutResponseInterface
     */
    /**
     * The quote a session is held on. Exposed because the cart capability works the same quote through
     * a different response shape.
     *
     * @param string $maskedCartId
     * @return \Magento\Quote\Api\Data\CartInterface
     */
    public function getCartByMaskedId(string $maskedCartId): \Magento\Quote\Api\Data\CartInterface;

    public function completeCheckout(string $checkoutId, PaymentInterface $paymentData): CheckoutResponseInterface;
}
