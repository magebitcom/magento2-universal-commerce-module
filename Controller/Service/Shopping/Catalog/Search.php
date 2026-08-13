<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Controller\Service\Shopping\Catalog;

use Magebit\UcpSpec\Api\Shopping\Types\MessageErrorInterfaceFactory;
use Magebit\UniversalCommerce\Api\Service\Shopping\CatalogHandlerInterface;
use Magebit\UniversalCommerce\Controller\ApiController;
use Magebit\UniversalCommerce\Model\Config;
use Magebit\UniversalCommerce\Model\IdempotencyHandler;
use Magebit\UniversalCommerce\Model\RequestClassBuilder;
use Magebit\UniversalCommerce\Model\Validation\RequestValidator;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json as ResultJson;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use JsonSerializable;
use Psr\Log\LoggerInterface;


class Search extends ApiController
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
     * @param CatalogHandlerInterface $catalogHandler
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
        protected readonly CatalogHandlerInterface $catalogHandler
    ) {
        parent::__construct(
            $resultJsonFactory,
            $request,
            $requestValidator,
            $requestClassBuilder,
            $config,
            $messageFactory,
            $idempotencyHandler,
            $logger
        );
    }

    /**
     * @return ResultJson
     */
    public function execute(): ResultJson
    {
        return $this->errorBoundary(function (): ResultJson {
            $payload = $this->decodedBody();
            $pagination = is_array($payload['pagination'] ?? null) ? $payload['pagination'] : [];
            $limit = $pagination['limit'] ?? null;
            $cursor = $pagination['cursor'] ?? null;
            $query = $payload['query'] ?? null;

            $response = $this->catalogHandler->search(
                is_string($query) ? $query : null,
                is_numeric($limit) ? (int) $limit : null,
                is_string($cursor) ? $cursor : null
            );

            if ($response instanceof JsonSerializable) {
                return $this->makeJsonResponse($response);
            }

            throw new LocalizedException(__('Internal server error'));
        });
    }
}
