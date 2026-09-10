<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Api;

use Magebit\UniversalCommerce\Api\Data\OrderAdjustmentInterface;
use Magento\Framework\Exception\CouldNotSaveException;

interface OrderAdjustmentRepositoryInterface
{
    /**
     * Records the adjustment, replacing any earlier submission carrying the same identifier: a repeated
     * request must not add a second copy of the same adjustment.
     *
     * @param int $orderId
     * @param string $adjustmentId
     * @param string $payload
     * @return OrderAdjustmentInterface
     * @throws CouldNotSaveException
     */
    public function record(int $orderId, string $adjustmentId, string $payload): OrderAdjustmentInterface;

    /**
     * @param int $orderId
     * @return OrderAdjustmentInterface[] Oldest first, so the order they were submitted in is kept
     */
    public function getByOrderId(int $orderId): array;
}
