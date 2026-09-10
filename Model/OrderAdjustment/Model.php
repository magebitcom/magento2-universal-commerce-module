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
use Magento\Framework\Model\AbstractModel;

class Model extends AbstractModel implements OrderAdjustmentInterface
{
    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(ResourceModel::class);
    }

    /**
     * @inheritDoc
     */
    public function getEntityId(): ?int
    {
        $value = $this->getData(self::ENTITY_ID);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @inheritDoc
     */
    public function getOrderId(): ?int
    {
        $value = $this->getData(self::ORDER_ID);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @inheritDoc
     */
    public function setOrderId(int $orderId): OrderAdjustmentInterface
    {
        return $this->setData(self::ORDER_ID, $orderId);
    }

    /**
     * @inheritDoc
     */
    public function getAdjustmentId(): ?string
    {
        $value = $this->getData(self::ADJUSTMENT_ID);

        return is_string($value) ? $value : null;
    }

    /**
     * @inheritDoc
     */
    public function setAdjustmentId(string $adjustmentId): OrderAdjustmentInterface
    {
        return $this->setData(self::ADJUSTMENT_ID, $adjustmentId);
    }

    /**
     * @inheritDoc
     */
    public function getPayload(): ?string
    {
        $value = $this->getData(self::PAYLOAD);

        return is_string($value) ? $value : null;
    }

    /**
     * @inheritDoc
     */
    public function setPayload(string $payload): OrderAdjustmentInterface
    {
        return $this->setData(self::PAYLOAD, $payload);
    }

    /**
     * @inheritDoc
     */
    public function getCreatedAt(): ?string
    {
        $value = $this->getData(self::CREATED_AT);

        return is_string($value) ? $value : null;
    }

    /**
     * @inheritDoc
     */
    public function getUpdatedAt(): ?string
    {
        $value = $this->getData(self::UPDATED_AT);

        return is_string($value) ? $value : null;
    }
}
