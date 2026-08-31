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

use Magebit\UcpSpec\Api\Shopping\CheckoutCompleteRequestInterface;
use Magebit\UcpSpec\Api\Shopping\CheckoutCompleteRequestInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\MessageErrorInterfaceFactory;
use Magebit\UniversalCommerce\Controller\ApiController;
use Magento\Framework\Controller\Result\Json as ResultJson;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\RequestInterface;
use Magebit\UniversalCommerce\Model\Validation\RequestValidator;
use Magebit\UniversalCommerce\Api\Service\Shopping\RestHandlerInterface;
use Magebit\UniversalCommerce\Model\Validation\ValidationResult;
use Magebit\UniversalCommerce\Model\RequestClassBuilder;
use Magebit\UniversalCommerce\Model\Config;
use Magebit\UniversalCommerce\Model\IdempotencyHandler;
use Magebit\UniversalCommerce\Model\Protocol\VersionNegotiator;
use Psr\Log\LoggerInterface;
use JsonSerializable;
use Magento\Framework\Exception\LocalizedException;

class Complete extends ApiController
{
    public function __construct(
        JsonFactory $resultJsonFactory,
        RequestInterface $request,
        RequestValidator $requestValidator,
        RequestClassBuilder $requestClassBuilder,
        Config $config,
        MessageErrorInterfaceFactory $messageFactory,
        IdempotencyHandler $idempotencyHandler,
        LoggerInterface $logger,
        VersionNegotiator $versionNegotiator,
        protected readonly RestHandlerInterface $restHandler,
        protected readonly CheckoutCompleteRequestInterfaceFactory $completeRequestFactory
    ) {
        parent::__construct(
            $resultJsonFactory,
            $request,
            $requestValidator,
            $requestClassBuilder,
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
        /** @var string|null $checkoutId */
        $checkoutId = $this->getHttpRequest()->getParam('checkout_id');

        if (!$checkoutId) {
            return $this->missingCheckoutId();
        }

        // The body is {"payment": {...}} — the payment object is nested, not the root.
        $completeRequest = $this->getAndValidateRequest(
            CheckoutCompleteRequestInterface::class,
            $this->completeRequestFactory->create(...)
        );

        if ($completeRequest instanceof ValidationResult) {
            return $this->validationResultToResponse($completeRequest);
        }

        $paymentData = $completeRequest->getPayment();

        if ($idempotencyResponse = $this->handleIdempotency()) {
            return $idempotencyResponse;
        }

        return $this->errorBoundary(function () use ($checkoutId, $paymentData) {
            $completeCheckoutResponse = $this->restHandler->completeCheckout($checkoutId, $paymentData);

            if ($completeCheckoutResponse instanceof JsonSerializable) {
                $this->idempotencyHandler->storeResponse($this->getHttpRequest(), $completeCheckoutResponse, 200);

                return $this->makeJsonResponse($completeCheckoutResponse);
            }

            throw new LocalizedException(__('Internal server error'));
        });
    }
}
