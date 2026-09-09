<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Controller\Testing;

use Magebit\UniversalCommerce\Model\Order\SessionOrderLookup;
use Magebit\UniversalCommerce\Model\Simulation\Secret;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json as ResultJson;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\ShipOrderInterface;
use Psr\Log\LoggerInterface;

/**
 * Ships an agent-placed order so the conformance suite can watch the order event that follows.
 *
 * This is not part of the UCP specification. The conformance suite hardcodes this path and sends a
 * shared secret, following the reference sample server. It stays switched off until a secret is put
 * in app/etc/env.php, and it only ever ships orders that this protocol placed itself.
 */
class SimulateShipping implements ActionInterface, CsrfAwareActionInterface
{
    /**
     * Header the conformance suite sends the shared secret in.
     */
    public const HEADER_SECRET = 'Simulation-Secret';

    /**
     * @param JsonFactory $resultJsonFactory
     * @param Http $request
     * @param Secret $secret
     * @param SessionOrderLookup $orderLookup
     * @param ShipOrderInterface $shipOrder
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly JsonFactory $resultJsonFactory,
        private readonly Http $request,
        private readonly Secret $secret,
        private readonly SessionOrderLookup $orderLookup,
        private readonly ShipOrderInterface $shipOrder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return ResultJson
     */
    public function execute(): ResultJson
    {
        // With no secret configured the endpoint behaves as if it were not routed at all, so nothing is
        // exposed on a store that never opted in.
        if (!$this->secret->isConfigured()) {
            return $this->error(404, 'Not found.');
        }

        $sent = $this->request->getHeader(self::HEADER_SECRET);

        if (!$this->secret->matches(is_string($sent) ? $sent : null)) {
            return $this->error(403, 'A valid simulation secret is required.');
        }

        // The same identifier the protocol hands out: the checkout session that placed the order.
        /** @var string|null $checkoutId */
        $checkoutId = $this->request->getParam('order_id');
        $order = $this->orderLookup->find((string) $checkoutId);
        $entityId = $order?->getEntityId();

        // An order the protocol did not place is none of this endpoint's business, so it reads as
        // missing rather than refused.
        if ($order === null || !is_numeric($entityId)) {
            return $this->error(404, 'Order not found.');
        }

        if (!$order->canShip()) {
            return $this->error(409, 'This order cannot be shipped.');
        }

        try {
            $shipmentId = $this->shipOrder->execute((int) $entityId);
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);

            return $this->error(500, 'The order could not be shipped.');
        }

        return $this->json(200, [
            'order_id' => (string) $checkoutId,
            'label' => $order->getIncrementId(),
            'shipment_id' => $shipmentId,
        ]);
    }

    /**
     * @param int $statusCode
     * @param string $message
     * @return ResultJson
     */
    private function error(int $statusCode, string $message): ResultJson
    {
        return $this->json($statusCode, ['message' => $message]);
    }

    /**
     * @param int $statusCode
     * @param array<string, mixed> $data
     * @return ResultJson
     */
    private function json(int $statusCode, array $data): ResultJson
    {
        $result = $this->resultJsonFactory->create();
        $result->setHttpResponseCode($statusCode);
        $result->setData($data);

        return $result;
    }

    /**
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
