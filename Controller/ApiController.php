<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Controller;

use JsonSerializable;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json as ResultJson;
use Magento\Framework\DataObject;
use Magebit\UniversalCommerce\Model\Validation\RequestValidator;
use Magebit\UniversalCommerce\Model\Validation\ValidationResult;
use Magebit\UniversalCommerce\Model\RequestClassBuilder;
use Magebit\UcpSpec\Api\Shopping\Types\ErrorResponseInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MessageErrorInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MessageErrorInterfaceFactory;
use Magebit\UcpSpec\Api\UcpErrorInterface;
use Magebit\UniversalCommerce\Api\UniversalCommerceProtocolInterface;
use Magebit\UniversalCommerce\Exception\UcpException;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Exception\LocalizedException;
use Magebit\UniversalCommerce\Model\Config;
use Magebit\UniversalCommerce\Model\IdempotencyHandler;
use Psr\Log\LoggerInterface;

abstract class ApiController implements ActionInterface, CsrfAwareActionInterface
{
    /**
     * Trace header the spec marks required on every request, and which responses echo back.
     */
    public const HEADER_REQUEST_ID = 'Request-Id';

    /**
     * @param JsonFactory $resultJsonFactory
     * @param RequestInterface $request
     * @param RequestValidator $requestValidator
     * @param RequestClassBuilder $requestClassBuilder
     * @param Config $config
     * @param MessageErrorInterfaceFactory $messageFactory
     * @param IdempotencyHandler $idempotencyHandler
     * @param LoggerInterface $logger
     */
    public function __construct(
        protected readonly JsonFactory $resultJsonFactory,
        protected readonly RequestInterface $request,
        protected readonly RequestValidator $requestValidator,
        protected readonly RequestClassBuilder $requestClassBuilder,
        protected readonly Config $config,
        protected readonly MessageErrorInterfaceFactory $messageFactory,
        protected readonly IdempotencyHandler $idempotencyHandler,
        protected readonly LoggerInterface $logger
    ) {
    }

    /**
     * Error boundary
     *
     * @param callable $callback
     * @return ResultJson
     */
    public function errorBoundary(callable $callback): ResultJson
    {
        try {
            $this->assertRequestId();

            return $callback();
        } catch (UcpException $e) {
            return $this->makeErrorResponse(
                [$this->errorMessage($e->getErrorCode(), $e->getMessage())],
                $e->getStatusCode()
            );
        } catch (LocalizedException $e) {
            return $this->makeErrorResponse(
                [$this->errorMessage('invalid_request', $e->getMessage())],
                500
            );
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);

            return $this->makeErrorResponse(
                [$this->errorMessage('server_error', 'An unexpected error occurred.')],
                500
            );
        }
    }

    /**
     * @template T
     * @param class-string<T> $classType
     * @param callable $factory
     * @return T|ValidationResult
     */
    public function getAndValidateRequest(string $classType, callable $factory): mixed
    {
        $request = $this->getHttpRequest();
        $data = $request->getContent();
        $rawData = (array) json_decode($data, true);

        $validationResult = $this->requestValidator->validate($rawData, $classType);

        if (!$validationResult->isValid()) {
            return $validationResult;
        }

        $requestObject = $factory();
        $this->requestClassBuilder->populateWithArray($requestObject, $rawData, $classType);

        return $requestObject;
    }

    /**
     * Handle idempotency
     *
     * @return ResultJson|null
     */
    public function handleIdempotency(): ?ResultJson
    {
        try {
            if ($idempotencyResponse = $this->idempotencyHandler->handle($this->getHttpRequest())) {
                return $idempotencyResponse;
            }
        } catch (LocalizedException $e) {
            return $this->makeErrorResponse(
                [$this->errorMessage('invalid_request', $e->getMessage())],
                400
            );
        }

        return null;
    }

    /**
     * Convert ValidationResult to UCP-compliant error response
     *
     * @param ValidationResult $validationResult
     * @return ResultJson
     */
    public function validationResultToResponse(ValidationResult $validationResult): ResultJson
    {
        $messages = [];

        foreach ($validationResult->getErrors() as $path => $content) {
            $messages[] = $this->errorMessage(
                'validation_error',
                (string)$content,
                MessageErrorInterface::SEVERITY_REQUIRES_BUYER_INPUT,
                $path === '' ? null : $this->jsonPath((string)$path)
            );
        }

        return $this->makeErrorResponse($messages, 400);
    }

    /**
     * @return void
     * @throws UcpException If the trace header is missing or is not a UUID
     */
    protected function assertRequestId(): void
    {
        $requestId = $this->getHttpRequest()->getHeader(self::HEADER_REQUEST_ID);

        if (!is_string($requestId) || $requestId === '') {
            if (!$this->config->isRequestIdRequired()) {
                return;
            }

            throw new UcpException(
                __('The %1 header is required.', self::HEADER_REQUEST_ID),
                'invalid_request',
                400
            );
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $requestId) !== 1) {
            throw new UcpException(
                __('The %1 header must be a UUID.', self::HEADER_REQUEST_ID),
                'invalid_request',
                400
            );
        }
    }

    /**
     * The validator reports dot-notation paths; `message.path` is an RFC 9535 JSONPath, which roots at
     * `$` and brackets array positions.
     *
     * @param string $dotted
     * @return string
     */
    public function jsonPath(string $dotted): string
    {
        return '$.' . preg_replace('/\.(\d+)(?=\.|$)/', '[$1]', $dotted);
    }

    /**
     * Every session-scoped action needs the identifier the router captured, and reports its absence
     * identically. Kept here so the four of them cannot drift into four different message shapes.
     *
     * @return ResultJson
     */
    protected function missingCheckoutId(): ResultJson
    {
        return $this->makeErrorResponse(
            [$this->errorMessage('invalid_request', 'A checkout session identifier is required.')],
            400
        );
    }

    /**
     * The spec requires type, code, content and severity on every error, so they are set here rather
     * than at each construction site.
     *
     * @param string $code Error code
     * @param string $content Human-readable text
     * @param string $severity One of the spec's severity values
     * @param string|null $path JSONPath the error applies to
     * @return MessageErrorInterface
     */
    protected function errorMessage(
        string $code,
        string $content,
        string $severity = MessageErrorInterface::SEVERITY_RECOVERABLE,
        ?string $path = null
    ): MessageErrorInterface {
        /** @var MessageErrorInterface $message */
        $message = $this->messageFactory->create();
        $message->setType(MessageErrorInterface::TYPE_ERROR)
            ->setCode($code)
            ->setContent($content)
            ->setSeverity($severity);

        if ($path !== null) {
            $message->setPath($path);
        }

        return $message;
    }

    /**
     * The envelope is `types/error_response.json`, which forbids additional properties: the status
     * belongs to the UCP metadata, and the per-message severity says what the platform may do next.
     * Keyed off the generated constants so a schema rename fails the build rather than the wire.
     *
     * @param array<MessageErrorInterface> $messages
     * @param int $statusCode
     * @return ResultJson
     */
    public function makeErrorResponse(array $messages, int $statusCode = 400): ResultJson
    {
        return $this->makeJsonResponse([
            ErrorResponseInterface::KEY_UCP => [
                UcpErrorInterface::KEY_VERSION => UniversalCommerceProtocolInterface::SPEC_VERSION,
                UcpErrorInterface::KEY_STATUS => UcpErrorInterface::STATUS_ERROR,
            ],
            ErrorResponseInterface::KEY_MESSAGES => $messages,
        ], $statusCode);
    }

    /**
     * Make JSON response
     *
     * @param array<mixed>|DataObject|JsonSerializable $data
     * @param int $statusCode
     * @return ResultJson
     */
    public function makeJsonResponse(array|DataObject|JsonSerializable $data, int $statusCode = 200): ResultJson
    {
        $resultJson = $this->resultJsonFactory->create();
        $resultJson->setData($data);
        $resultJson->setHttpResponseCode($statusCode);

        $requestId = $this->getHttpRequest()->getHeader(self::HEADER_REQUEST_ID);

        if (is_string($requestId) && $requestId !== '') {
            $resultJson->setHeader(self::HEADER_REQUEST_ID, $requestId, true);
        }

        return $resultJson;
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

    /**
     * @return Http
     */
    public function getHttpRequest(): Http
    {
        /** @var Http $request */
        $request = $this->request;

        if (!$request instanceof Http) {
            throw new LocalizedException(__('Invalid request'));
        }

        return $request;
    }
}
