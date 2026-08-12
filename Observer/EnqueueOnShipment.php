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
use Magento\Sales\Model\Order\Shipment;

/**
 * A shipment is an entry in the order's fulfillment event log, which is the whole point of the log.
 *
 * Bound to the commit-after event, not save-after: at save-after the shipment row exists but its items do
 * not, so the body would report a shipment of nothing and the order as still unshipped.
 */
class EnqueueOnShipment implements ObserverInterface
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
        $shipment = $observer->getEvent()->getData('shipment');

        if ($shipment instanceof Shipment) {
            $this->notifier->notify($shipment->getOrder());
        }
    }
}
