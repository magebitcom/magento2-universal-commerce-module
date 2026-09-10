<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Setup\Patch\Data;

use Magebit\UniversalCommerce\Model\IdempotencyHandler;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class BackfillOrderLinks implements DataPatchInterface
{
    /**
     * Left declared in db_schema.xml on purpose, along with its order_id column. Declarative schema
     * runs before data patches, so a release that dropped either would delete the rows below before
     * this patch could read them.
     */
    private const SOURCE_TABLE = 'ucp_checkout_meta';

    private const TARGET_TABLE = 'agentic_checkout_order_link';

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    /**
     * Copies every session-to-order link into the shared table under this module's scope.
     *
     * @return $this
     */
    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $source = $this->moduleDataSetup->getTable(self::SOURCE_TABLE);
        $target = $this->moduleDataSetup->getTable(self::TARGET_TABLE);

        if (!$connection->isTableExists($source)) {
            return $this;
        }

        $select = $connection->select()
            ->from($source, [
                // A module constant, not input: quote() is typed mixed upstream and cannot be cast
                // under level 9, and there is nothing here to escape.
                new Expression("'" . IdempotencyHandler::SCOPE . "'"),
                'checkout_id',
                'quote_id',
                'order_id',
            ])
            // Deliberately unfiltered beyond requiring a link to exist. Unlike the idempotency copy,
            // a session-to-order link has no expiry and no cron that deletes it, so every row is live
            // no matter how old — filtering here would lose data rather than bound the work. Losing
            // one means a completed checkout reports canceled for good.
            ->where('order_id IS NOT NULL');

        // INSERT_IGNORE makes the patch re-runnable, so a run interrupted partway is safe to repeat.
        $connection->query(
            $connection->insertFromSelect(
                $select,
                $target,
                ['scope', 'session_id', 'quote_id', 'order_id'],
                AdapterInterface::INSERT_IGNORE
            )
        );

        return $this;
    }

    /**
     * @return array<string>
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    public function getAliases(): array
    {
        return [];
    }
}
