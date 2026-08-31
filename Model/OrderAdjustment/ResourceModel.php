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
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ResourceModel extends AbstractDb
{
    private const TABLE_NAME = 'ucp_order_adjustment';

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(self::TABLE_NAME, OrderAdjustmentInterface::ENTITY_ID);
    }
}
