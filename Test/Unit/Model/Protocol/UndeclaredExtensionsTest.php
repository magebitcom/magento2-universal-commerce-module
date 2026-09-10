<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit\Model\Protocol;

use Magebit\UcpSpec\Api\Shopping\Types\MessageInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterfaceFactory;
use Magebit\UcpSpec\Data\Shopping\Types\Message;
use Magebit\UniversalCommerce\Api\Service\Shopping\CheckoutResponseInterface;
use Magebit\UniversalCommerce\Api\ServiceInterface;
use Magebit\UniversalCommerce\Model\Discovery\ServiceRegistry;
use Magebit\UniversalCommerce\Model\Protocol\UndeclaredExtensions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UndeclaredExtensionsTest extends TestCase
{
    private const EXTENSIONS = ['ap2' => 'dev.ucp.shopping.ap2_mandate'];

    /**
     * @return void
     */
    public function testAnExtensionTheStoreDoesNotDeclareIsReported(): void
    {
        $warnings = $this->reporter(['dev.ucp.shopping.checkout'])->report(['ap2' => ['x' => 'y']]);

        $this->assertCount(1, $warnings);
        $this->assertSame('warning', $warnings[0]->getType());
        $this->assertSame(UndeclaredExtensions::CODE_NOT_ACCEPTED, $warnings[0]->getCode());
        $this->assertSame('$.ap2', $warnings[0]->getPath());
        $this->assertStringContainsString('dev.ucp.shopping.ap2_mandate', (string) $warnings[0]->getContent());
    }

    /**
     * @return void
     */
    public function testAnExtensionTheStoreDeclaresIsNotReported(): void
    {
        $declared = ['dev.ucp.shopping.checkout', 'dev.ucp.shopping.ap2_mandate'];

        $this->assertSame([], $this->reporter($declared)->report(['ap2' => ['x' => 'y']]));
    }

    /**
     * @return void
     */
    public function testARequestThatCarriesNoExtensionIsNotReportedOn(): void
    {
        $this->assertSame([], $this->reporter(['dev.ucp.shopping.checkout'])->report(['payment' => []]));
    }

    /**
     * The specification leaves request objects open, so an unknown field is not by itself something to
     * complain about — only the extensions this store knows of and does not implement.
     *
     * @return void
     */
    public function testAnUnknownFieldIsNotMistakenForAnExtension(): void
    {
        $this->assertSame([], $this->reporter(['dev.ucp.shopping.checkout'])->report(['whatever' => 1]));
    }

    /**
     * @return void
     */
    public function testTheWarningIsAddedBesideAnyMessagesTheResponseAlreadyCarries(): void
    {
        $existing = $this->createMock(MessageInterface::class);
        $response = $this->createMock(CheckoutResponseInterface::class);
        $response->method('getMessages')->willReturn([$existing]);
        $response->expects($this->once())
            ->method('setMessages')
            ->with($this->callback(static function (array $messages) use ($existing): bool {
                return count($messages) === 2 && $messages[0] === $existing;
            }));

        $this->reporter(['dev.ucp.shopping.checkout'])->annotate($response, ['ap2' => []]);
    }

    /**
     * @return void
     */
    public function testAResponseIsLeftAloneWhenThereIsNothingToSay(): void
    {
        $response = $this->createMock(CheckoutResponseInterface::class);
        $response->expects($this->never())->method('setMessages');

        $this->reporter(['dev.ucp.shopping.checkout'])->annotate($response, ['payment' => []]);
    }

    /**
     * @param string[] $declaredCapabilities
     * @return UndeclaredExtensions
     */
    private function reporter(array $declaredCapabilities): UndeclaredExtensions
    {
        $service = $this->createMock(ServiceInterface::class);
        $service->method('getCapabilities')->willReturn(array_fill_keys($declaredCapabilities, []));

        $registry = $this->createMock(ServiceRegistry::class);
        $registry->method('getService')->willReturn($service);

        $messageFactory = $this->createMock(MessageInterfaceFactory::class);
        $messageFactory->method('create')->willReturnCallback(
            static fn (array $arguments = []): Message => new Message($arguments['data'] ?? [])
        );

        return new UndeclaredExtensions($registry, $messageFactory, self::EXTENSIONS);
    }
}
