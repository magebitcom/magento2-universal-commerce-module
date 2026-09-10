<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\IdempotencyKey;

use Magebit\AgenticCore\Model\Idempotency\Purge;
use Magebit\UniversalCommerce\Model\Config;

/**
 * Cron entry point that purges stored idempotent responses past this module's configured TTL.
 */
class Cleanup
{
    /**
     * @param Purge $purge
     * @param Config $config
     */
    public function __construct(
        private readonly Purge $purge,
        private readonly Config $config
    ) {
    }

    /**
     * @return int Number of deleted rows.
     */
    public function execute(): int
    {
        return $this->purge->execute($this->config->getIdempotencyTtlHours());
    }
}
