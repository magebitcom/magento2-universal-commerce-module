<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Controller\Service\Shopping\Cart;

use Magebit\UniversalCommerce\Api\Service\Shopping\CheckoutCreateRequestInterface;
use Magebit\UniversalCommerce\Api\Service\Shopping\CheckoutCreateRequestInterfaceFactory;
use Magebit\UniversalCommerce\Model\Validation\ValidationResult;
use Magebit\UcpSpec\Api\Shopping\Types\MessageErrorInterfaceFactory;
use Magebit\UniversalCommerce\Api\Service\Shopping\CartHandlerInterface;
use Magebit\UniversalCommerce\Controller\ApiController;
use Magebit\UniversalCommerce\Model\Config;
use Magebit\UniversalCommerce\Model\IdempotencyHandler;
use Magebit\UniversalCommerce\Model\Protocol\VersionNegotiator;
use Magebit\UniversalCommerce\Model\RequestClassBuilder;
use Magebit\UniversalCommerce\Model\Validation\RequestValidator;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json as ResultJson;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use JsonSerializable;
use Psr\Log\LoggerInterface;

class Create extends ApiController
{
    /**
     * @param JsonFactory $resultJsonFactory
     * @param RequestInterface $request
     * @param RequestValidator $requestValidator
     * @param RequestClassBuilder $requestClassBuilder
     * @param Config $config
     * @param MessageErrorInterfaceFactory $messageFactory
     * @param IdempotencyHandler $idempotencyHandler
     * @param LoggerInterface $logger
     * @param CheckoutCreateRequestInterfaceFactory $cartCreateRequestFactory
     * @param CartHandlerInterface $cartHandler
     */
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
        protected readonly CheckoutCreateRequestInterfaceFactory $cartCreateRequestFactory,
        protected readonly CartHandlerInterface $cartHandler
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
        // The cart create body is a subset of the checkout one, so the same request class carries it.
        $request = $this->getAndValidateRequest(
            CheckoutCreateRequestInterface::class,
            $this->cartCreateRequestFactory->create(...)
        );

        if ($request instanceof ValidationResult) {
            return $this->validationResultToResponse($request);
        }

        if ($idempotencyResponse = $this->handleIdempotency()) {
            return $idempotencyResponse;
        }

        return $this->errorBoundary(function () use ($request) {
            $response = $this->cartHandler->createCart($request);

            if ($response instanceof JsonSerializable) {
                $this->idempotencyHandler->storeResponse($this->getHttpRequest(), $response, 201);

                return $this->makeJsonResponse($response, 201);
            }

            throw new LocalizedException(__('Internal server error'));
        });
    }
}
