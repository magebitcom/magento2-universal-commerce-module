<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\Service\Shopping\Converter;

use Magebit\AgenticCore\Model\Money\MinorUnits;
use Magebit\UcpSpec\Api\Shopping\DiscountResponseAppliedDiscountInterface;
use Magebit\UcpSpec\Api\Shopping\DiscountResponseAppliedDiscountInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\DiscountResponseDiscountsObjectInterface;
use Magebit\UcpSpec\Api\Shopping\DiscountResponseDiscountsObjectInterfaceFactory;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;

class QuoteToDiscountResponse
{
    private const DEFAULT_TITLE = 'Discount';

    /**
     * @param DiscountResponseDiscountsObjectInterfaceFactory $discountsObjectFactory
     * @param DiscountResponseAppliedDiscountInterfaceFactory $appliedDiscountFactory
     * @param MinorUnits $minorUnits
     */
    public function __construct(
        protected readonly DiscountResponseDiscountsObjectInterfaceFactory $discountsObjectFactory,
        protected readonly DiscountResponseAppliedDiscountInterfaceFactory $appliedDiscountFactory,
        protected readonly MinorUnits $minorUnits,
    ) {
    }

    /**
     * @param CartInterface $quote
     * @return DiscountResponseDiscountsObjectInterface|null
     */
    public function convert(CartInterface $quote): ?DiscountResponseDiscountsObjectInterface
    {
        /** @var Quote $quote */
        $couponCode = $quote->getCouponCode() ?: null;
        $amount = $this->discountedAmount($quote);

        // A code that applied nothing still has to be echoed, so the agent can see it was rejected.
        if ($couponCode === null && $amount <= 0.0) {
            return null;
        }

        /** @var DiscountResponseDiscountsObjectInterface $discounts */
        $discounts = $this->discountsObjectFactory->create();
        $discounts->setCodes($couponCode !== null ? [$couponCode] : []);
        $discounts->setApplied($amount > 0.0 ? [$this->appliedDiscount($quote, $couponCode, $amount)] : []);

        return $discounts;
    }

    /**
     * Both halves are read as magnitudes: Magento stores item discounts as a subtotal difference and
     * shipping discounts as a negative amount on the address.
     *
     * @param Quote $quote
     * @return float Positive discount total in major currency units
     */
    private function discountedAmount(Quote $quote): float
    {
        $items = abs((float)$quote->getSubtotal() - (float)$quote->getSubtotalWithDiscount());
        $shipping = abs((float)$this->discountAddress($quote)->getShippingDiscountAmount());

        return $items + $shipping;
    }

    /**
     * @param Quote $quote
     * @param string|null $couponCode
     * @param float $amount Positive discount total in major currency units
     * @return DiscountResponseAppliedDiscountInterface
     */
    private function appliedDiscount(
        Quote $quote,
        ?string $couponCode,
        float $amount
    ): DiscountResponseAppliedDiscountInterface {
        $currencyCode = $quote->getCurrency()?->getStoreCurrencyCode() ?? 'USD';
        $description = $this->discountAddress($quote)->getDiscountDescription();

        /** @var DiscountResponseAppliedDiscountInterface $applied */
        $applied = $this->appliedDiscountFactory->create();
        $applied->setTitle((string)($description ?: $couponCode ?: self::DEFAULT_TITLE));
        $applied->setAmount($this->minorUnits->convert($amount, $currencyCode));
        $applied->setAutomatic($couponCode === null);

        if ($couponCode !== null) {
            $applied->setCode($couponCode);
        }

        return $applied;
    }

    /**
     * A virtual quote has no shippable address, so its discount lives on the billing one.
     *
     * @param Quote $quote
     * @return Address
     */
    private function discountAddress(Quote $quote): Address
    {
        return $quote->getIsVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
    }
}
