<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit\Model;

use Magebit\AgenticCore\Api\Data\IdempotencyRecordInterface;
use Magebit\AgenticCore\Model\Idempotency\ClaimOutcome;
use Magebit\AgenticCore\Model\Idempotency\ClaimResult;
use Magebit\AgenticCore\Model\Idempotency\Coordinator;
use Magebit\AgenticCore\Model\Idempotency\Gate;
use Magebit\AgenticCore\Model\Idempotency\RequestHasher;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterfaceFactory;
use Magebit\UniversalCommerce\Model\IdempotencyHandler;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json as ResultJson;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IdempotencyHandlerTest extends TestCase
{
    private const KEY = 'test-key';
    private const BODY = '{"line_items":[]}';

    /** @var Coordinator&MockObject */
    private Coordinator $coordinator;

    /** @var IdempotencyHandler */
    private IdempotencyHandler $handler;

    /**
     * Data the handler wrote onto the result, so the envelope can be asserted rather than its type.
     *
     * @var array<string, mixed>
     */
    private array $written = [];

    /**
     * Message payloads the spec factory was asked to build.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $messages = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->written = [];
        $this->messages = [];

        $this->coordinator = $this->createMock(Coordinator::class);

        $result = $this->createMock(ResultJson::class);
        $result->method('setData')->willReturnCallback(
            function ($data) use ($result): ResultJson {
                $this->written['data'] = $data;
                return $result;
            }
        );
        $result->method('setJsonData')->willReturnCallback(
            function (string $data) use ($result): ResultJson {
                $this->written['json'] = $data;
                return $result;
            }
        );
        $result->method('setHttpResponseCode')->willReturnCallback(
            function ($code) use ($result): ResultJson {
                $this->written['code'] = $code;
                return $result;
            }
        );
        $result->method('setHeader')->willReturnCallback(
            function (string $name, $value) use ($result): ResultJson {
                $this->written['header:' . $name] = (string) $value;
                return $result;
            }
        );

        $resultJsonFactory = $this->createMock(JsonFactory::class);
        $resultJsonFactory->method('create')->willReturn($result);

        $messageFactory = $this->createMock(MessageInterfaceFactory::class);
        $messageFactory->method('create')->willReturnCallback(
            function (array $arguments): MessageInterface {
                /** @var array<string, mixed> $data */
                $data = $arguments['data'] ?? [];
                $this->messages[] = $data;

                return $this->createMock(MessageInterface::class);
            }
        );

        $gate = new Gate($this->coordinator, new RequestHasher(), $this->encryptor());

        $this->handler = new IdempotencyHandler($gate, $resultJsonFactory, $messageFactory);
    }

    /**
     * Stands in for the framework's encryptor, including its `<key>:<cipher>:<payload>` envelope and
     * its habit of returning garbage rather than failing when handed a value it never encrypted.
     *
     * @return EncryptorInterface
     */
    private function encryptor(): EncryptorInterface
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(
            static fn (?string $value): string => '0:3:' . base64_encode((string) $value)
        );
        $encryptor->method('decrypt')->willReturnCallback(
            static function (?string $value): string {
                $payload = substr((string) $value, strlen('0:3:'));
                $decoded = base64_decode($payload, true);

                return $decoded === false ? "\x00garbage" : $decoded;
            }
        );

        return $encryptor;
    }

    /**
     * @return void
     */
    public function testAClaimedKeyLetsTheCallerProceed(): void
    {
        $this->coordinator->method('claim')->willReturn(new ClaimResult(ClaimOutcome::Claimed));

        $this->assertNull($this->handler->handle($this->request('POST', self::KEY)));
    }

    /**
     * Replaying a safe method would hide current state, so the coordinator is never consulted.
     *
     * @return void
     */
    public function testASafeMethodIsNeverReplayed(): void
    {
        $this->coordinator->expects($this->never())->method('claim');

        $this->assertNull($this->handler->handle($this->request('GET', self::KEY)));
    }

    /**
     * @return void
     */
    public function testAMissingKeyLetsTheCallerProceed(): void
    {
        $this->coordinator->expects($this->never())->method('claim');

        $this->assertNull($this->handler->handle($this->request('POST', null)));
    }

    /**
     * @return void
     */
    public function testAnInFlightClaimIsRefusedWithRetryAfter(): void
    {
        $this->coordinator->method('claim')->willReturn(new ClaimResult(ClaimOutcome::InFlight));

        $this->assertInstanceOf(ResultJson::class, $this->handler->handle($this->request('POST', self::KEY)));
        $this->assertSame(409, $this->written['code']);
        $this->assertSame('idempotency_key_in_flight', $this->messages[0]['code']);
        $this->assertSame('1', $this->written['header:Retry-After']);
    }

    /**
     * @return void
     */
    public function testAConflictIsRefusedWithoutRetryAfter(): void
    {
        $this->coordinator->method('claim')->willReturn(new ClaimResult(ClaimOutcome::Conflict));

        $this->assertInstanceOf(ResultJson::class, $this->handler->handle($this->request('POST', self::KEY)));
        $this->assertSame(409, $this->written['code']);
        $this->assertSame('idempotency_key_reuse', $this->messages[0]['code']);
        $this->assertArrayNotHasKey('header:Retry-After', $this->written);
    }

    /**
     * The stored body is replayed byte for byte under its stored status.
     *
     * @return void
     */
    public function testAStoredResponseIsReplayedVerbatim(): void
    {
        $record = $this->createMock(IdempotencyRecordInterface::class);
        $record->method('getResponseBody')->willReturn('0:3:' . base64_encode('{"id":"abc"}'));
        $record->method('getResponseStatus')->willReturn(201);

        $this->coordinator->method('claim')->willReturn(new ClaimResult(ClaimOutcome::Replay, $record));

        $this->assertInstanceOf(ResultJson::class, $this->handler->handle($this->request('POST', self::KEY)));
        $this->assertSame('{"id":"abc"}', $this->written['json']);
        $this->assertSame(201, $this->written['code']);
    }

    /**
     * Rows written before this module encrypted its bodies are still inside their TTL on an upgraded
     * install. Decrypting one speculatively would replay binary garbage to the agent.
     *
     * @return void
     */
    public function testAPlaintextRowWrittenBeforeEncryptionStillReplays(): void
    {
        $record = $this->createMock(IdempotencyRecordInterface::class);
        $record->method('getResponseBody')->willReturn('{"id":"legacy"}');
        $record->method('getResponseStatus')->willReturn(200);

        $this->coordinator->method('claim')->willReturn(new ClaimResult(ClaimOutcome::Replay, $record));

        $this->handler->handle($this->request('POST', self::KEY));

        $this->assertSame('{"id":"legacy"}', $this->written['json']);
        $this->assertSame(200, $this->written['code']);
    }

    /**
     * @return void
     */
    public function testStoringAResponseHandsTheBodyToTheCoordinator(): void
    {
        $this->coordinator->expects($this->once())
            ->method('storeResponse')
            ->with(
                IdempotencyHandler::SCOPE,
                self::KEY,
                $this->anything(),
                201,
                '0:3:' . base64_encode('{"id":"abc"}')
            )
            ->willReturn($this->createMock(IdempotencyRecordInterface::class));

        $this->handler->storeResponse(
            $this->request('POST', self::KEY),
            new class implements \JsonSerializable {
                /**
                 * @return array<string, string>
                 */
                public function jsonSerialize(): array
                {
                    return ['id' => 'abc'];
                }
            },
            201
        );
    }

    /**
     * @param string $method
     * @param string|null $key
     * @return Http&MockObject
     */
    private function request(string $method, ?string $key): Http
    {
        $request = $this->createMock(Http::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getHeader')->willReturn($key ?? false);
        $request->method('getContent')->willReturn(self::BODY);
        $request->method('getPathInfo')->willReturn('/ucp/shopping/checkout-sessions');
        $request->method('getQuery')->willReturn([]);

        return $request;
    }
}
