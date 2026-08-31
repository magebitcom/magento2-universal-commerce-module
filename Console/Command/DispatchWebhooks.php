<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Console\Command;

use Magebit\UniversalCommerce\Cron\DispatchWebhooks as DispatchDueWebhooks;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sends the order-event webhooks that are due now, instead of waiting for the next cron run. Useful
 * when a delivery has to go out at once, and to a conformance run, which cannot wait a minute.
 */
class DispatchWebhooks extends Command
{
    private const COMMAND_NAME = 'magebit:ucp:dispatch-webhooks';

    /**
     * @param DispatchDueWebhooks $dispatchDueWebhooks
     * @param State $state
     * @param string|null $name
     */
    public function __construct(
        private readonly DispatchDueWebhooks $dispatchDueWebhooks,
        private readonly State $state,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME);
        $this->setDescription('Send the order-event webhooks that are due now.');

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Magento\Framework\Exception\LocalizedException $exception) {
            // Already set by another command in the same process; nothing to do.
        }

        // The same work the cron does, so the two cannot answer differently.
        $output->writeln(sprintf('<info>delivered</info> %d', $this->dispatchDueWebhooks->execute()));

        return Command::SUCCESS;
    }
}
