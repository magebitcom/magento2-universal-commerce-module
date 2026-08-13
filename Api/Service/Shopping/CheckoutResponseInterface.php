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

use Magebit\UcpSpec\Api\Shopping\DiscountResponseDiscountsObjectInterface;
use Magebit\UcpSpec\Api\Shopping\FulfillmentResponseCheckoutInterface;

/**
 * The checkout response carrying every extension we implement. UCP composes each extension onto the
 * base checkout separately, so no single spec type declares them together.
 */
interface CheckoutResponseInterface extends FulfillmentResponseCheckoutInterface
{
    /**
     * @return DiscountResponseDiscountsObjectInterface|null
     */
    public function getDiscounts(): ?DiscountResponseDiscountsObjectInterface;

    /**
     * @param DiscountResponseDiscountsObjectInterface|null $discounts
     * @return self
     */
    public function setDiscounts(?DiscountResponseDiscountsObjectInterface $discounts): self;

    /**
     * Set only on the cart endpoints. The cart capability adds this to the checkout response rather
     * than defining an envelope of its own.
     *
     * @return string|null
     */
    public function getCartId(): ?string;

    /**
     * @param string|null $cartId
     * @return self
     */
    public function setCartId(?string $cartId): self;
}
