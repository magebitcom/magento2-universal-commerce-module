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
use Magento\Sales\Model\Order;

/**
 * Cancellation is the one lifecycle change no sales document represents. The rest — moving to processing,
 * to complete — follow from an invoice or a shipment, which have their own observers, so watching every
 * order save instead would send the same event twice.
 */
class EnqueueOnCancel implements ObserverInterface
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
        $order = $observer->getEvent()->getData('order');

        if ($order instanceof Order) {
            $this->notifier->notify($order);
        }
    }
}
