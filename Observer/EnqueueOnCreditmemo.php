<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Observer;

use Magebit\UniversalCommerce\Model\Webhook\OrderEventNotifier;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Creditmemo;

/**
 * A credit memo is a refund adjustment, which moves money and changes the active quantities.
 *
 * Bound to the commit-after event so the memo's items and the order's own refunded quantities are both in
 * place before the body is built.
 */
class EnqueueOnCreditmemo implements ObserverInterface
{
    /**
     * @param OrderEventNotifier $notifier
     */
    public function __construct(
        private readonly OrderEventNotifier $notifier
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $creditmemo = $observer->getEvent()->getData('creditmemo');

        if ($creditmemo instanceof Creditmemo) {
            $this->notifier->notify($creditmemo->getOrder());
        }
    }
}
