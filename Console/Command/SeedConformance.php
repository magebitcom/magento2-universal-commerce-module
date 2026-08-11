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

use Magebit\UniversalCommerce\Model\Seed\ConformanceFixtures;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Seeds the fixed catalogue and coupons the UCP conformance run expects.
 */
class SeedConformance extends Command
{
    private const COMMAND_NAME = 'magebit:seed:ucp-conformance';

    /**
     * @param ConformanceFixtures $fixtures
     * @param State $state
     * @param string|null $name
     */
    public function __construct(
        private readonly ConformanceFixtures $fixtures,
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
        $this->setDescription('Seed the fixed products and coupons the UCP conformance run expects.');

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

        try {
            foreach ($this->fixtures->applyProducts() as $sku) {
                $output->writeln('<info>product</info> ' . $sku);
            }

            foreach ($this->fixtures->applyRules() as $code) {
                $output->writeln('<info>coupon</info> ' . $code);
            }
        } catch (\Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Conformance fixtures are in place.</info>');

        return Command::SUCCESS;
    }
}
