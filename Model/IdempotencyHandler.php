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
use Magebit\AgenticCore\Model\Idempotency\DecisionOutcome;
use Magebit\AgenticCore\Model\Idempotency\Gate;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterfaceFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json as ResultJson;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\CouldNotSaveException;

/**
 * Maps the shared gate's decisions onto this protocol's envelope. The arbitration itself is shared.
 */
class IdempotencyHandler
{
    /**
     * Partitions this module's rows in the shared table.
     */
    public const SCOPE = 'ucp';

    /**
     * Advertised in Retry-After while another caller still owns the claim.
     */
    private const RETRY_AFTER_SECONDS = 1;

    /**
     * @param Gate $gate
     * @param JsonFactory $resultJsonFactory
     * @param MessageInterfaceFactory $messageFactory
     */
    public function __construct(
        protected readonly Gate $gate,
        protected readonly JsonFactory $resultJsonFactory,
        protected readonly MessageInterfaceFactory $messageFactory
    ) {
    }

    /**
     * @param Http $request
     * @return ResultJson|null Null means the caller owns the key and must execute the operation.
     */
    public function handle(Http $request): ?ResultJson
    {
        $decision = $this->gate->decide(self::SCOPE, $request);

        return match ($decision->outcome) {
            DecisionOutcome::Proceed, DecisionOutcome::KeyMissing => null,
            DecisionOutcome::Conflict => $this->makeConflictResponse(
                'idempotency_key_reuse',
                'Idempotency-Key was already used for a different request.'
            ),
            DecisionOutcome::InFlight => $this->makeInFlightResponse(),
            DecisionOutcome::Replay => $this->makeReplayResponse(
                (string) $decision->body,
                (int) $decision->status
            ),
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
        $this->gate->remember(self::SCOPE, $request, (string) json_encode($response), $status);
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
     * @param string $body
     * @param int $status
     * @return ResultJson
     */
    protected function makeReplayResponse(string $body, int $status): ResultJson
    {
        $result = $this->resultJsonFactory->create();
        $result->setJsonData($body);
        $result->setHttpResponseCode($status);

        return $result;
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
