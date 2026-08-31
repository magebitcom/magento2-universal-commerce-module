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

use Magebit\AgenticCore\Model\Webhook\Dispatcher;
use Magebit\UniversalCommerce\Model\Config;
use Magebit\UniversalCommerce\Model\IdempotencyHandler;
use Psr\Log\LoggerInterface;

/**
 * Attempts this module's due order-event deliveries. The backoff, not the schedule, paces retries.
 */
class DispatchWebhooks
{
    /**
     * @param Dispatcher $dispatcher
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly Config $config,
        private readonly LoggerInterface $logger
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

        try {
            $delivered = $this->dispatcher->dispatchDue(IdempotencyHandler::SCOPE);

            if ($delivered > 0) {
                $this->logger->info(sprintf('Delivered %d order event webhook(s).', $delivered));
            }

            return $delivered;
        } catch (\Exception $exception) {
            $this->logger->error(
                sprintf('Error dispatching order event webhooks: %s', $exception->getMessage()),
                ['exception' => $exception]
            );

            return 0;
        }
    }
}
