<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\OrderAdjustment;

use Magebit\UniversalCommerce\Api\Data\OrderAdjustmentInterface;
use Magebit\UniversalCommerce\Api\OrderAdjustmentRepositoryInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Psr\Log\LoggerInterface;

class Repository implements OrderAdjustmentRepositoryInterface
{
    /**
     * @param ResourceModel $resourceModel
     * @param ModelFactory $adjustmentFactory
     * @param CollectionFactory $collectionFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceModel $resourceModel,
        private readonly ModelFactory $adjustmentFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function record(int $orderId, string $adjustmentId, string $payload): OrderAdjustmentInterface
    {
        $adjustment = $this->findExisting($orderId, $adjustmentId) ?? $this->adjustmentFactory->create();
        $adjustment->setOrderId($orderId);
        $adjustment->setAdjustmentId($adjustmentId);
        $adjustment->setPayload($payload);

        try {
            /** @var Model $adjustment */
            $this->resourceModel->save($adjustment);
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage());

            throw new CouldNotSaveException(
                __('Could not record the order adjustment: %1', $exception->getMessage()),
                $exception
            );
        }

        return $adjustment;
    }

    /**
     * @inheritDoc
     */
    public function getByOrderId(int $orderId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(OrderAdjustmentInterface::ORDER_ID, ['eq' => $orderId]);
        $collection->setOrder(OrderAdjustmentInterface::ENTITY_ID, 'ASC');

        $adjustments = [];

        foreach ($collection as $adjustment) {
            if ($adjustment instanceof OrderAdjustmentInterface) {
                $adjustments[] = $adjustment;
            }
        }

        return $adjustments;
    }

    /**
     * @param int $orderId
     * @param string $adjustmentId
     * @return Model|null
     */
    private function findExisting(int $orderId, string $adjustmentId): ?Model
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(OrderAdjustmentInterface::ORDER_ID, ['eq' => $orderId]);
        $collection->addFieldToFilter(OrderAdjustmentInterface::ADJUSTMENT_ID, ['eq' => $adjustmentId]);
        $collection->setPageSize(1);

        $existing = $collection->getFirstItem();

        return $existing instanceof Model && $existing->getEntityId() !== null ? $existing : null;
    }
}
