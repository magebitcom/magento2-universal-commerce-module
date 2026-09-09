<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\UniversalCommerce\Controller\Service\Shopping;

use Magebit\UcpSpec\Api\Shopping\OrderUpdateRequestInterface;
use Magebit\UcpSpec\Api\Shopping\OrderUpdateRequestInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\MessageErrorInterfaceFactory;
use Magebit\UniversalCommerce\Api\Service\Shopping\OrderHandlerInterface;
use Magebit\UniversalCommerce\Controller\ApiController;
use Magebit\UniversalCommerce\Model\Config;
use Magebit\UniversalCommerce\Model\IdempotencyHandler;
use Magebit\UniversalCommerce\Model\Protocol\VersionNegotiator;
use Magebit\AgenticCore\Model\Request\Hydrator;
use Magebit\AgenticCore\Model\Validation\RequestValidator;
use Magebit\AgenticCore\Model\Validation\ValidationResult;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json as ResultJson;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use JsonSerializable;
use Psr\Log\LoggerInterface;

class UpdateOrder extends ApiController
{
    public function __construct(
        JsonFactory $resultJsonFactory,
        RequestInterface $request,
        RequestValidator $requestValidator,
        Hydrator $hydrator,
        Config $config,
        MessageErrorInterfaceFactory $messageFactory,
        IdempotencyHandler $idempotencyHandler,
        LoggerInterface $logger,
        VersionNegotiator $versionNegotiator,
        private readonly OrderUpdateRequestInterfaceFactory $orderUpdateRequestFactory,
        private readonly OrderHandlerInterface $orderHandler
    ) {
        parent::__construct(
            $resultJsonFactory,
            $request,
            $requestValidator,
            $hydrator,
            $config,
            $messageFactory,
            $idempotencyHandler,
            $logger,
            $versionNegotiator
        );
    }

    /**
     * @return ResultJson
     */
    public function execute(): ResultJson
    {
        /** @var string|null $orderId */
        $orderId = $this->getHttpRequest()->getParam('order_id');

        if (!$orderId) {
            return $this->makeErrorResponse(
                [$this->errorMessage('invalid_request', 'An order identifier is required.')],
                400
            );
        }

        $orderUpdateRequest = $this->getAndValidateRequest(
            OrderUpdateRequestInterface::class,
            $this->orderUpdateRequestFactory->create(...)
        );

        if ($orderUpdateRequest instanceof ValidationResult) {
            return $this->validationResultToResponse($orderUpdateRequest);
        }

        if ($idempotencyResponse = $this->handleIdempotency()) {
            return $idempotencyResponse;
        }

        return $this->errorBoundary(function () use ($orderId, $orderUpdateRequest): ResultJson {
            $order = $this->orderHandler->updateOrder($orderId, $orderUpdateRequest);

            if (!$order instanceof JsonSerializable) {
                throw new LocalizedException(__('Internal server error'));
            }

            $this->idempotencyHandler->storeResponse($this->getHttpRequest(), $order, 200);

            return $this->makeJsonResponse($order);
        });
    }
}
