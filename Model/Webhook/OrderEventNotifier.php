<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\Webhook;

use Magebit\AgenticCore\Api\OrderLinkRepositoryInterface;
use Magebit\AgenticCore\Model\Webhook\Dispatcher;
use Magebit\UniversalCommerce\Api\CheckoutMetaRepositoryInterface;
use Magebit\UniversalCommerce\Model\Config;
use Magebit\UniversalCommerce\Model\IdempotencyHandler;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\OrderToOrderResponse;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Queues an order lifecycle event for the agent that created the checkout. Only queues: delivery,
 * headers and retries belong to the shared dispatcher, so nothing an order does waits on a receiver.
 */
class OrderEventNotifier
{
    /**
     * @param Dispatcher $dispatcher
     * @param OrderLinkRepositoryInterface $orderLinkRepository
     * @param CheckoutMetaRepositoryInterface $checkoutMetaRepository
     * @param OrderToOrderResponse $orderConverter
     * @param IdentityGeneratorInterface $identityGenerator
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly OrderLinkRepositoryInterface $orderLinkRepository,
        private readonly CheckoutMetaRepositoryInterface $checkoutMetaRepository,
        private readonly OrderToOrderResponse $orderConverter,
        private readonly IdentityGeneratorInterface $identityGenerator,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Order $order
     * @return void
     */
    public function notify(Order $order): void
    {
        // Checked before anything is built: a merchant who has not enabled delivery must not accumulate
        // rows nobody will ever send, and the credential scheme is still undecided.
        if (!$this->config->areWebhooksEnabled((int) $order->getStoreId())) {
            return;
        }

        $checkoutId = $this->checkoutIdOf($order);

        if ($checkoutId === null) {
            return;
        }

        $url = $this->webhookUrlOf($checkoutId);

        // An agent that declared no webhook URL in its profile is not asking to be told.
        if ($url === null) {
            return;
        }

        try {
            $this->dispatcher->enqueue(
                IdempotencyHandler::SCOPE,
                $url,
                (string) json_encode($this->orderConverter->convert($order, $checkoutId)),
                // The event's own identifier, which the delivery reports as Webhook-Id and keeps across
                // retries. One per event, not one per order or per session.
                $this->identityGenerator->generateId()
            );
        } catch (\Exception $exception) {
            // An order that already happened must not be undone because its notification could not be
            // queued.
            $this->logger->critical('Could not queue an order event webhook', [
                'exception' => $exception,
                'order_id' => $order->getIncrementId(),
            ]);
        }
    }

    /**
     * @param Order $order
     * @return string|null Null when the order came from no session of this protocol
     */
    private function checkoutIdOf(Order $order): ?string
    {
        $entityId = $order->getEntityId();

        if (!is_numeric($entityId)) {
            return null;
        }

        return $this->orderLinkRepository->findSessionId(IdempotencyHandler::SCOPE, (int) $entityId);
    }

    /**
     * @param string $checkoutId
     * @return string|null
     */
    private function webhookUrlOf(string $checkoutId): ?string
    {
        try {
            $url = (string) $this->checkoutMetaRepository->getByCheckoutId($checkoutId)->getWebhookUrl();
        } catch (NoSuchEntityException $exception) {
            return null;
        }

        return $url === '' ? null : $url;
    }
}
