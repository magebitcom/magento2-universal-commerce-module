<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\Order;

use Magebit\AgenticCore\Model\Order\Note;
use Magebit\UcpSpec\Api\Shopping\Types\AdjustmentInterface;
use Magebit\UniversalCommerce\Api\OrderAdjustmentRepositoryInterface;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderToAdjustments;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Sales\Model\Order;

/**
 * Takes down the post-order adjustments an agent asks for. The store does not act on them: an agent
 * cannot refund itself, so the request is recorded and put in front of whoever handles the order.
 */
class AdjustmentRecorder
{
    /**
     * @param OrderAdjustmentRepositoryInterface $repository
     * @param OrderToAdjustments $adjustmentsConverter
     * @param Note $orderNote
     */
    public function __construct(
        private readonly OrderAdjustmentRepositoryInterface $repository,
        private readonly OrderToAdjustments $adjustmentsConverter,
        private readonly Note $orderNote
    ) {
    }

    /**
     * @param Order $order
     * @param AdjustmentInterface[] $submitted
     * @return int How many were new
     * @throws CouldNotSaveException
     */
    public function record(Order $order, array $submitted): int
    {
        $entityId = $order->getEntityId();

        if (!is_numeric($entityId) || $submitted === []) {
            return 0;
        }

        $known = $this->adjustmentsConverter->knownIds($order);
        $recorded = 0;

        foreach ($submitted as $adjustment) {
            $id = $adjustment->getId();

            if (in_array($id, $known, true)) {
                continue;
            }

            $this->repository->record((int) $entityId, $id, (string) json_encode($adjustment));
            $this->orderNote->add($order, $this->noteFor($adjustment));
            $recorded++;
        }

        return $recorded;
    }

    /**
     * @param AdjustmentInterface $adjustment
     * @return string
     */
    private function noteFor(AdjustmentInterface $adjustment): string
    {
        $note = sprintf(
            'An agent asked for a %s over UCP (%s), status %s.',
            $adjustment->getType(),
            $adjustment->getId(),
            $adjustment->getStatus()
        );

        $description = $adjustment->getDescription();

        return $description === null || $description === '' ? $note : $note . ' ' . $description;
    }
}
