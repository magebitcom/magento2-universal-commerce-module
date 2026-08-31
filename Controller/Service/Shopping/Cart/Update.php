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

use Magebit\UniversalCommerce\Api\Service\Shopping\CheckoutUpdateRequestInterface;
use Magebit\UniversalCommerce\Api\Service\Shopping\CheckoutUpdateRequestInterfaceFactory;
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

class Update extends ApiController
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
     * @param CheckoutUpdateRequestInterfaceFactory $cartUpdateRequestFactory
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
        protected readonly CheckoutUpdateRequestInterfaceFactory $cartUpdateRequestFactory,
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
        /** @var string|null $cartId */
        $cartId = $this->getHttpRequest()->getParam('cart_id');

        if (!$cartId) {
            return $this->missingCartId();
        }

        $request = $this->getAndValidateRequest(
            CheckoutUpdateRequestInterface::class,
            $this->cartUpdateRequestFactory->create(...)
        );

        if ($request instanceof ValidationResult) {
            return $this->validationResultToResponse($request);
        }

        if ($idempotencyResponse = $this->handleIdempotency()) {
            return $idempotencyResponse;
        }

        return $this->errorBoundary(function () use ($cartId, $request) {
            $response = $this->cartHandler->updateCart($cartId, $request);

            if ($response instanceof JsonSerializable) {
                $this->idempotencyHandler->storeResponse($this->getHttpRequest(), $response, 200);

                return $this->makeJsonResponse($response);
            }

            throw new LocalizedException(__('Internal server error'));
        });
    }
}
