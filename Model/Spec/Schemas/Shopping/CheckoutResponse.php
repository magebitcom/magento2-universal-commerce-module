<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\Spec\Schemas\Shopping;

use Magebit\UcpSpec\Api\Shopping\CartResponseCheckoutInterface;
use Magebit\UcpSpec\Api\Shopping\DiscountResponseCheckoutInterface;
use Magebit\UcpSpec\Api\Shopping\DiscountResponseDiscountsObjectInterface;
use Magebit\UcpSpec\Data\Shopping\FulfillmentResponseCheckout;
use Magebit\UniversalCommerce\Api\Service\Shopping\CheckoutResponseInterface;

/**
 * The base checkout response plus the fulfillment and discount extensions, which the spec ships as
 * separate schemas and therefore never composes into one type of its own.
 */
class CheckoutResponse extends FulfillmentResponseCheckout implements CheckoutResponseInterface
{
    /**
     * @return DiscountResponseDiscountsObjectInterface|null
     */
    public function getDiscounts(): ?DiscountResponseDiscountsObjectInterface
    {
        return $this->instanceOrNull(
            DiscountResponseCheckoutInterface::KEY_DISCOUNTS,
            DiscountResponseDiscountsObjectInterface::class
        );
    }

    /**
     * @param DiscountResponseDiscountsObjectInterface|null $discounts
     * @return self
     */
    public function setDiscounts(?DiscountResponseDiscountsObjectInterface $discounts): self
    {
        return $this->set(DiscountResponseCheckoutInterface::KEY_DISCOUNTS, $discounts);
    }

    /**
     * @return string|null
     */
    public function getCartId(): ?string
    {
        return $this->stringOrNull(CartResponseCheckoutInterface::KEY_CART_ID);
    }

    /**
     * @param string|null $cartId
     * @return CheckoutResponseInterface
     */
    public function setCartId(?string $cartId): CheckoutResponseInterface
    {
        $this->set(CartResponseCheckoutInterface::KEY_CART_ID, $cartId);

        return $this;
    }
}
