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

use Magebit\UniversalCommerce\Api\Service\Shopping\CheckoutUpdateRequestInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\MessageErrorInterfaceFactory;
use Magebit\UniversalCommerce\Api\Service\Shopping\CheckoutUpdateRequestInterface;
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

class Update extends ApiController
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
        protected readonly CheckoutUpdateRequestInterfaceFactory $checkoutUpdateRequestFactory,
        protected readonly RestHandlerInterface $restHandler
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

        $checkoutUpdateRequest = $this->getAndValidateRequest(
            CheckoutUpdateRequestInterface::class,
            $this->checkoutUpdateRequestFactory->create(...)
        );

        if ($checkoutUpdateRequest instanceof ValidationResult) {
            return $this->validationResultToResponse($checkoutUpdateRequest);
        }

        if ($idempotencyResponse = $this->handleIdempotency()) {
            return $idempotencyResponse;
        }

        return $this->errorBoundary(function () use ($checkoutId, $checkoutUpdateRequest) {
            $checkoutResponse = $this->restHandler->updateCheckout($checkoutId, $checkoutUpdateRequest);

            if ($checkoutResponse instanceof JsonSerializable) {
                $this->idempotencyHandler->storeResponse($this->getHttpRequest(), $checkoutResponse, 200);

                return $this->makeJsonResponse($checkoutResponse);
            }

            throw new LocalizedException(__('Internal server error'));
        });
    }
}
