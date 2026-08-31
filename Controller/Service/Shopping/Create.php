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

use Magebit\UniversalCommerce\Api\Service\Shopping\CheckoutCreateRequestInterface;
use Magebit\UniversalCommerce\Api\Service\Shopping\CheckoutCreateRequestInterfaceFactory;
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

class Create extends ApiController
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
        protected readonly CheckoutCreateRequestInterfaceFactory $checkoutCreateRequestFactory,
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
        $checkoutCreateRequest = $this->getAndValidateRequest(
            CheckoutCreateRequestInterface::class,
            $this->checkoutCreateRequestFactory->create(...)
        );

        if ($checkoutCreateRequest instanceof ValidationResult) {
            return $this->validationResultToResponse($checkoutCreateRequest);
        }

        if ($idempotencyResponse = $this->handleIdempotency()) {
            return $idempotencyResponse;
        }

        return $this->errorBoundary(function () use ($checkoutCreateRequest) {
            $checkoutResponse = $this->restHandler->createCheckout($checkoutCreateRequest);

            if ($checkoutResponse instanceof JsonSerializable) {
                $this->idempotencyHandler->storeResponse($this->getHttpRequest(), $checkoutResponse, 201);

                return $this->makeJsonResponse($checkoutResponse, 201);
            }

            throw new LocalizedException(__('Internal server error'));
        });
    }
}
