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
use Magebit\UcpSpec\Api\Shopping\Types\TotalResponseInterface;
use Magebit\UniversalCommerce\Api\Data\TotalTypeInterface;
use Magebit\UcpSpec\Api\Shopping\Types\TotalResponseInterfaceFactory;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;

/**
 * Convert Quote to Totals Response
 */
class QuoteToTotalsResponse
{
    /**
     * Emission order, matching how the spec describes the total as
     * subtotal - discount + fulfillment + tax + fee.
     */
    private const TYPE_ORDER = [
        TotalTypeInterface::TYPE_ITEMS_DISCOUNT,
        TotalTypeInterface::TYPE_SUBTOTAL,
        TotalTypeInterface::TYPE_DISCOUNT,
        TotalTypeInterface::TYPE_FULFILLMENT,
        TotalTypeInterface::TYPE_TAX,
        TotalTypeInterface::TYPE_FEE,
        TotalTypeInterface::TYPE_TOTAL,
    ];

    /**
     * @param TotalResponseInterfaceFactory $totalResponseFactory
     * @param MinorUnits $minorUnits
     * @param array<string, string> $typeMapping
     */
    public function __construct(
        private readonly TotalResponseInterfaceFactory $totalResponseFactory,
        private readonly MinorUnits $minorUnits,
        private readonly array $typeMapping = [],
    ) {
    }

    /**
     * Convert quote to totals response array
     *
     * @param CartInterface $cart
     * @return array<TotalResponseInterface>
     */
    public function convert(CartInterface $cart): array
    {
        /** @var Quote $cart */
        $currencyCode = $cart->getCurrency()?->getStoreCurrencyCode() ?? 'USD';
        $amounts = [];
        $labels = [];

        foreach ($cart->getTotals() as $cartTotal) {
            $type = $this->mapType((string) $cartTotal->getCode());

            if ($type === null) {
                continue;
            }

            // The spec requires amount >= 0, so a Magento discount's negative value
            // is emitted as its magnitude and subtracted by the consumer.
            $amounts[$type] = ($amounts[$type] ?? 0.0) + abs((float) $cartTotal->getValue());
            $labels[$type] ??= (string) $cartTotal->getTitle();
        }

        $amounts = $this->withRequiredTotals($cart, $amounts);

        $totals = [];

        foreach (self::TYPE_ORDER as $type) {
            if (!array_key_exists($type, $amounts)) {
                continue;
            }

            /** @var TotalResponseInterface $total */
            $total = $this->totalResponseFactory->create();
            $total->setType($type);
            $total->setDisplayText($labels[$type] ?? $this->fallbackLabel($type));
            $total->setAmount($this->minorUnits->convert($amounts[$type], $currencyCode));

            $totals[] = $total;
        }

        return $totals;
    }

    /**
     * Subtotal and total are required on every checkout response, so fall back to the
     * quote's own figures when Magento did not emit a matching total row.
     *
     * @param Quote $cart
     * @param array<string, float> $amounts
     * @return array<string, float>
     */
    private function withRequiredTotals(Quote $cart, array $amounts): array
    {
        if (!isset($amounts[TotalTypeInterface::TYPE_SUBTOTAL])) {
            $address = $cart->getIsVirtual() ? $cart->getBillingAddress() : $cart->getShippingAddress();
            $subtotal = $address->getSubtotal() ?? $cart->getSubtotal();
            $amounts[TotalTypeInterface::TYPE_SUBTOTAL] = abs((float) $subtotal);
        }

        if (!isset($amounts[TotalTypeInterface::TYPE_TOTAL])) {
            $amounts[TotalTypeInterface::TYPE_TOTAL] = abs((float) $cart->getGrandTotal());
        }

        return $amounts;
    }

    /**
     * Map a Magento total code to a UCP type, or null when it has no spec equivalent.
     *
     * @param string $magentoCode
     * @return string|null
     */
    private function mapType(string $magentoCode): ?string
    {
        $type = $this->typeMapping[$magentoCode] ?? null;

        return in_array($type, self::TYPE_ORDER, true) ? $type : null;
    }

    /**
     * @param string $type
     * @return string
     */
    private function fallbackLabel(string $type): string
    {
        return ucfirst(str_replace('_', ' ', $type));
    }
}
