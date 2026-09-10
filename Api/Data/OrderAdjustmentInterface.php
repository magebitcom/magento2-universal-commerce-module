<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Api\Data;

/**
 * One post-order adjustment an agent asked this store to record against an order.
 */
interface OrderAdjustmentInterface
{
    public const ENTITY_ID = 'entity_id';
    public const ORDER_ID = 'order_id';
    public const ADJUSTMENT_ID = 'adjustment_id';
    public const PAYLOAD = 'payload';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    /**
     * @return int|null
     */
    public function getEntityId(): ?int;

    /**
     * @return int|null
     */
    public function getOrderId(): ?int;

    /**
     * @param int $orderId
     * @return $this
     */
    public function setOrderId(int $orderId): self;

    /**
     * @return string|null
     */
    public function getAdjustmentId(): ?string;

    /**
     * @param string $adjustmentId
     * @return $this
     */
    public function setAdjustmentId(string $adjustmentId): self;

    /**
     * The adjustment as the agent submitted it, as JSON.
     *
     * @return string|null
     */
    public function getPayload(): ?string;

    /**
     * @param string $payload
     * @return $this
     */
    public function setPayload(string $payload): self;

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * @return string|null
     */
    public function getUpdatedAt(): ?string;
}
