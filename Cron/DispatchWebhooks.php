<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Cron;

use Magebit\AgenticCore\Model\Webhook\DueDeliveries;
use Magebit\UniversalCommerce\Model\Config;

/**
 * Attempts this module's due order-event deliveries.
 */
class DispatchWebhooks
{
    /**
     * @param DueDeliveries $dueDeliveries
     * @param Config $config
     */
    public function __construct(
        private readonly DueDeliveries $dueDeliveries,
        private readonly Config $config
    ) {
    }

    /**
     * @return int How many deliveries went out
     */
    public function execute(): int
    {
        // Checked before querying so a merchant who has not enabled delivery pays nothing for the cron.
        if (!$this->config->areWebhooksEnabled()) {
            return 0;
        }

        return $this->dueDeliveries->execute();
    }
}
