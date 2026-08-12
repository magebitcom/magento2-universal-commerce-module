<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model;

use JsonSerializable;
use Magebit\AgenticCore\Api\Data\IdempotencyRecordInterface;
use Magebit\AgenticCore\Model\Idempotency\ClaimOutcome;
use Magebit\AgenticCore\Model\Idempotency\Coordinator;
use Magebit\AgenticCore\Model\Idempotency\RequestHasher;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterfaceFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json as ResultJson;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\CouldNotSaveException;

/**
 * Maps the shared claim outcomes onto this protocol's envelope. The arbitration itself is shared.
 */
class IdempotencyHandler
{
    public const HEADER_NAME = 'Idempotency-Key';

    /**
     * Partitions this module's rows in the shared table.
     */
    public const SCOPE = 'ucp';

    /**
     * Replaying a safe method would hide current state, so idempotency applies to unsafe methods only.
     */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    /**
     * Advertised in Retry-After while another caller still owns the claim.
     */
    private const RETRY_AFTER_SECONDS = 1;

    /**
     * Envelope the framework's encryptor puts in front of every value it produces, as
     * `<keyVersion>:<cipherVersion>:<base64>`. A stored response body is JSON, so it always starts
     * with `{` or `[` and can never collide with this.
     */
    private const ENCRYPTED_PREFIX_PATTERN = '/^\d+:\d+:/';

    /**
     * @param Coordinator $coordinator
     * @param RequestHasher $hasher
     * @param JsonFactory $resultJsonFactory
     * @param MessageInterfaceFactory $messageFactory
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        protected readonly Coordinator $coordinator,
        protected readonly RequestHasher $hasher,
        protected readonly JsonFactory $resultJsonFactory,
        protected readonly MessageInterfaceFactory $messageFactory,
        protected readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * Claim the key, or return the replay / conflict response the caller must send instead.
     *
     * @param Http $request
     * @return ResultJson|null Null means the caller owns the key and must execute the operation.
     */
    public function handle(Http $request): ?ResultJson
    {
        $key = $this->getKey($request);

        if ($key === null) {
            return null;
        }

        $result = $this->coordinator->claim(self::SCOPE, $key, $this->hasher->hash($request));

        return match ($result->outcome) {
            ClaimOutcome::Claimed => null,
            ClaimOutcome::Conflict => $this->makeConflictResponse(
                'idempotency_key_reuse',
                'Idempotency-Key was already used for a different request.'
            ),
            ClaimOutcome::InFlight => $this->makeInFlightResponse(),
            ClaimOutcome::Replay => $this->makeReplayResponse($result->record),
        };
    }

    /**
     * @param Http $request
     * @param JsonSerializable $response
     * @param int $status
     * @return void
     * @throws CouldNotSaveException
     */
    public function storeResponse(Http $request, JsonSerializable $response, int $status): void
    {
        $key = $this->getKey($request);

        if ($key === null) {
            return;
        }

        $this->coordinator->storeResponse(
            self::SCOPE,
            $key,
            $this->hasher->hash($request),
            $status,
            $this->encryptor->encrypt((string) json_encode($response))
        );
    }

    /**
     * @param Http $request
     * @return string|null Null when idempotency does not apply to this request.
     */
    protected function getKey(Http $request): ?string
    {
        if (in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            return null;
        }

        $key = $request->getHeader(self::HEADER_NAME);

        if (!is_string($key) || $key === '') {
            return null;
        }

        return $key;
    }

    /**
     * @return ResultJson
     */
    protected function makeInFlightResponse(): ResultJson
    {
        $result = $this->makeConflictResponse(
            'idempotency_key_in_flight',
            'A request with this Idempotency-Key is still being processed.'
        );
        $result->setHeader('Retry-After', (string) self::RETRY_AFTER_SECONDS, true);

        return $result;
    }

    /**
     * @param IdempotencyRecordInterface|null $record
     * @return ResultJson
     */
    protected function makeReplayResponse(?IdempotencyRecordInterface $record): ResultJson
    {
        $result = $this->resultJsonFactory->create();
        $result->setJsonData($this->readBody($record?->getResponseBody() ?? ''));
        $result->setHttpResponseCode((int) $record?->getResponseStatus());

        return $result;
    }

    /**
     * Rows written before this module encrypted its stored bodies are still inside their TTL on an
     * upgraded install, and the encryptor hands back binary garbage rather than failing when given a
     * value it never encrypted — so the envelope is checked instead of decrypting speculatively.
     * The fallback stops being reachable one TTL window after deploy.
     *
     * @param string $body
     * @return string
     */
    private function readBody(string $body): string
    {
        if ($body === '' || !preg_match(self::ENCRYPTED_PREFIX_PATTERN, $body)) {
            return $body;
        }

        return (string) $this->encryptor->decrypt($body);
    }

    /**
     * @param string $code
     * @param string $message
     * @return ResultJson
     */
    protected function makeConflictResponse(string $code, string $message): ResultJson
    {
        /** @var MessageInterface $messageObject */
        $messageObject = $this->messageFactory->create(['data' => [
            'type' => 'error',
            'code' => $code,
            'message' => $message,
        ]]);

        $result = $this->resultJsonFactory->create();
        $result->setData([
            'status' => 'requires_escalation',
            'messages' => [$messageObject],
        ]);
        $result->setHttpResponseCode(409);

        return $result;
    }
}
