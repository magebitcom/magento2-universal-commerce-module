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

use Magebit\UniversalCommerce\Model\Config;
use Magebit\UniversalCommerce\Model\IdempotencyHandler;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class MigrateIdempotencyKeys implements DataPatchInterface
{
    /**
     * Left declared in db_schema.xml on purpose. Declarative schema runs before data patches, so a
     * release that dropped this table would delete the rows below before this patch could read them.
     */
    private const SOURCE_TABLE = 'ucp_idempotency_keys';

    private const TARGET_TABLE = 'agentic_idempotency';

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param Config $config
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly Config $config
    ) {
    }

    /**
     * Copies this module's still-live rows into the shared table under its own scope.
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
                'key',
                'request_hash',
                'response_status',
                'response_body',
                'created_at',
                'updated_at',
            ])
            // A row past its configured lifetime is already dead — the hourly cron exists to delete
            // it. Copying the whole history would move unbounded rows under maintenance mode for no
            // behavioural gain, so the work is capped at one TTL window of traffic.
            ->where(
                'created_at >= UTC_TIMESTAMP() - INTERVAL ? HOUR',
                max(1, $this->config->getIdempotencyTtlHours())
            );

        // INSERT_IGNORE makes the patch re-runnable, so a run interrupted partway is safe to repeat.
        $connection->query(
            $connection->insertFromSelect(
                $select,
                $target,
                [
                    'scope',
                    'idempotency_key',
                    'request_hash',
                    'response_status',
                    'response_body',
                    'created_at',
                    'updated_at',
                ],
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
