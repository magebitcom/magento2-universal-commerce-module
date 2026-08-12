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
use Magebit\UcpSpec\Api\Shopping\Types\TotalResponseInterfaceFactory;
use Magebit\UniversalCommerce\Api\Data\TotalTypeInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * The order's own totals. Unlike a quote, an order stores its breakdown in fixed columns rather than
 * in collector rows, so nothing here needs the code-to-type mapping the checkout's converter takes.
 */
class OrderToTotalsResponse
{
    /**
     * @param TotalResponseInterfaceFactory $totalFactory
     * @param MinorUnits $minorUnits
     */
    public function __construct(
        private readonly TotalResponseInterfaceFactory $totalFactory,
        private readonly MinorUnits $minorUnits
    ) {
    }

    /**
     * Shipping is taken excluding tax and the tax entry carries all of it, so the entries sum to the
     * grand total instead of counting shipping tax twice.
     *
     * @param OrderInterface $order
     * @return TotalResponseInterface[]
     */
    public function convert(OrderInterface $order): array
    {
        $currencyCode = (string) $order->getOrderCurrencyCode();
        $discount = abs((float) $order->getDiscountAmount());

        $totals = [
            $this->total(TotalTypeInterface::TYPE_SUBTOTAL, 'Subtotal', (float) $order->getSubtotal(), $currencyCode),
        ];

        if ($discount > 0) {
            $totals[] = $this->total(
                TotalTypeInterface::TYPE_DISCOUNT,
                (string) ($order->getDiscountDescription() ?: 'Discount'),
                -$discount,
                $currencyCode
            );
        }

        $shipping = (float) $order->getShippingAmount();

        if ($shipping > 0) {
            $totals[] = $this->total(
                TotalTypeInterface::TYPE_FULFILLMENT,
                (string) ($order->getShippingDescription() ?: 'Shipping'),
                $shipping,
                $currencyCode
            );
        }

        $tax = (float) $order->getTaxAmount();

        if ($tax > 0) {
            $totals[] = $this->total(TotalTypeInterface::TYPE_TAX, 'Tax', $tax, $currencyCode);
        }

        $totals[] = $this->total(
            TotalTypeInterface::TYPE_TOTAL,
            'Grand Total',
            (float) $order->getGrandTotal(),
            $currencyCode
        );

        return $totals;
    }

    /**
     * @param string $type
     * @param string $displayText
     * @param float $amount
     * @param string $currencyCode
     * @return TotalResponseInterface
     */
    private function total(
        string $type,
        string $displayText,
        float $amount,
        string $currencyCode
    ): TotalResponseInterface {
        /** @var TotalResponseInterface $total */
        $total = $this->totalFactory->create();
        $total->setType($type);
        $total->setDisplayText($displayText);
        $total->setAmount($this->minorUnits->convert($amount, $currencyCode));

        return $total;
    }
}
