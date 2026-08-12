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
use Magebit\UcpSpec\Api\Shopping\Types\AdjustmentInterface;
use Magebit\UcpSpec\Api\Shopping\Types\AdjustmentInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\AdjustmentLineItemsItemInterface;
use Magebit\UcpSpec\Api\Shopping\Types\AdjustmentLineItemsItemInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\TotalResponseInterface;
use Magebit\UcpSpec\Api\Shopping\Types\TotalResponseInterfaceFactory;
use Magebit\UniversalCommerce\Api\Data\TotalTypeInterface;
use Magebit\UniversalCommerce\Model\Timestamp;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;

/**
 * Post-order money movements. These sit outside the fulfillment log because a refund is not a delivery
 * and can happen with no shipment at all.
 */
class OrderToAdjustments
{
    /**
     * `adjustment.type` is an open string; these are the spec's documented values for the two events
     * Magento records natively.
     */
    public const TYPE_REFUND = 'refund';
    public const TYPE_CANCELLATION = 'cancellation';

    /**
     * @param AdjustmentInterfaceFactory $adjustmentFactory
     * @param AdjustmentLineItemsItemInterfaceFactory $lineItemFactory
     * @param TotalResponseInterfaceFactory $totalFactory
     * @param MinorUnits $minorUnits
     * @param Timestamp $timestamp
     */
    public function __construct(
        private readonly AdjustmentInterfaceFactory $adjustmentFactory,
        private readonly AdjustmentLineItemsItemInterfaceFactory $lineItemFactory,
        private readonly TotalResponseInterfaceFactory $totalFactory,
        private readonly MinorUnits $minorUnits,
        private readonly Timestamp $timestamp
    ) {
    }

    /**
     * @param Order $order
     * @param array<int, string> $lineItemIds Order item id to the identifier the response exposes
     * @return AdjustmentInterface[]
     */
    public function convert(Order $order, array $lineItemIds): array
    {
        $adjustments = $this->convertRefunds($order, $lineItemIds);
        $cancellation = $this->convertCancellation($order);

        if ($cancellation !== null) {
            $adjustments[] = $cancellation;
        }

        return $adjustments;
    }

    /**
     * @param Order $order
     * @param array<int, string> $lineItemIds
     * @return AdjustmentInterface[]
     */
    public function convertRefunds(Order $order, array $lineItemIds): array
    {
        $creditmemos = $order->getCreditmemosCollection();
        $adjustments = [];

        if ($creditmemos === false) {
            return $adjustments;
        }

        $currencyCode = (string) $order->getOrderCurrencyCode();

        foreach ($creditmemos as $creditmemo) {
            // The collection is not typed, so nothing but the check guarantees what came out of it.
            if (!$creditmemo instanceof Creditmemo) {
                continue;
            }

            $occurredAt = $this->timestamp->toRfc3339($creditmemo->getCreatedAt());

            if ($occurredAt === null) {
                continue;
            }

            /** @var AdjustmentInterface $adjustment */
            $adjustment = $this->adjustmentFactory->create();
            $adjustment->setId($this->documentId($creditmemo->getIncrementId(), $creditmemo->getEntityId()));
            $adjustment->setType(self::TYPE_REFUND);
            $adjustment->setOccurredAt($occurredAt);
            $adjustment->setStatus(AdjustmentInterface::STATUS_COMPLETED);

            $lineItems = $this->refundedLineItems($creditmemo, $lineItemIds);

            if ($lineItems !== []) {
                $adjustment->setLineItems($lineItems);
            }

            // Negative: the spec reads the sign as direction, and this money went back to the buyer.
            $adjustment->setTotals([
                $this->total(
                    TotalTypeInterface::TYPE_TOTAL,
                    'Refund',
                    -abs((float) $creditmemo->getGrandTotal()),
                    $currencyCode
                ),
            ]);

            $adjustments[] = $adjustment;
        }

        return $adjustments;
    }

    /**
     * Magento records a cancellation on the order itself rather than as a document, so there is no
     * timestamp for it beyond when the order last changed.
     *
     * @param Order $order
     * @return AdjustmentInterface|null
     */
    public function convertCancellation(Order $order): ?AdjustmentInterface
    {
        $occurredAt = $this->timestamp->toRfc3339($order->getUpdatedAt());

        if (!$order->isCanceled() || $occurredAt === null) {
            return null;
        }

        /** @var AdjustmentInterface $adjustment */
        $adjustment = $this->adjustmentFactory->create();
        $adjustment->setId('cancellation-' . (string) $order->getIncrementId());
        $adjustment->setType(self::TYPE_CANCELLATION);
        $adjustment->setOccurredAt($occurredAt);
        $adjustment->setStatus(AdjustmentInterface::STATUS_COMPLETED);

        return $adjustment;
    }

    /**
     * A sales document is identified by its increment id; the row id stands in only for one somehow
     * saved without one.
     *
     * @param mixed $incrementId
     * @param mixed $entityId
     * @return string
     */
    private function documentId(mixed $incrementId, mixed $entityId): string
    {
        if (is_scalar($incrementId) && (string) $incrementId !== '') {
            return (string) $incrementId;
        }

        return is_numeric($entityId) ? (string) (int) $entityId : '';
    }

    /**
     * @param Creditmemo $creditmemo
     * @param array<int, string> $lineItemIds
     * @return AdjustmentLineItemsItemInterface[]
     */
    private function refundedLineItems(Creditmemo $creditmemo, array $lineItemIds): array
    {
        $lineItems = [];

        foreach ($creditmemo->getAllItems() as $creditmemoItem) {
            $orderItemId = (int) $creditmemoItem->getOrderItemId();
            $quantity = (int) round((float) $creditmemoItem->getQty());

            if ($quantity < 1 || !isset($lineItemIds[$orderItemId])) {
                continue;
            }

            /** @var AdjustmentLineItemsItemInterface $lineItem */
            $lineItem = $this->lineItemFactory->create();
            $lineItem->setId($lineItemIds[$orderItemId]);
            // Negative because the quantity left the order, as the schema spells out for returns.
            $lineItem->setQuantity(-$quantity);

            $lineItems[] = $lineItem;
        }

        return $lineItems;
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
