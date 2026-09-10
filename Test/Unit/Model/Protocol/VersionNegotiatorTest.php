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

use Magebit\UniversalCommerce\Api\UniversalCommerceProtocolInterface;
use Magebit\UniversalCommerce\Exception\UcpException;
use Magebit\UniversalCommerce\Model\Protocol\VersionNegotiator;
use PHPUnit\Framework\TestCase;

class VersionNegotiatorTest extends TestCase
{
    private VersionNegotiator $negotiator;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->negotiator = new VersionNegotiator();
    }

    /**
     * @param string|null $header
     * @param string|null $expected
     * @return void
     * @dataProvider headerProvider
     */
    public function testTheVersionIsReadOffTheAgentHeader(?string $header, ?string $expected): void
    {
        $this->assertSame($expected, $this->negotiator->requested($header));
    }

    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function headerProvider(): array
    {
        return [
            'no header' => [null, null],
            'empty header' => ['', null],
            'profile only' => ['profile="https://agent.example/profile"', null],
            'quoted version' => ['profile="https://agent.example/p"; version="2026-04-08"', '2026-04-08'],
            'unquoted version' => ['profile="https://agent.example/p"; version=2026-04-08', '2026-04-08'],
            'version first' => ['version="2026-04-08"; profile="https://agent.example/p"', '2026-04-08'],
        ];
    }

    /**
     * @return void
     */
    public function testAnAgentThatNamesNoVersionIsServed(): void
    {
        $this->expectNotToPerformAssertions();

        $this->negotiator->assertSupported('profile="https://agent.example/profile"');
    }

    /**
     * @return void
     */
    public function testTheStoresOwnVersionIsServed(): void
    {
        $this->expectNotToPerformAssertions();

        $this->negotiator->assertSupported(
            'profile="x"; version="' . UniversalCommerceProtocolInterface::SPEC_VERSION . '"'
        );
    }

    /**
     * @return void
     */
    public function testAnyOtherVersionIsRefusedAsUnprocessable(): void
    {
        try {
            $this->negotiator->assertSupported('profile="x"; version="2099-01-01"');
            $this->fail('An unsupported version must be refused.');
        } catch (UcpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertSame(VersionNegotiator::CODE_UNSUPPORTED_VERSION, $exception->getErrorCode());
            $this->assertStringContainsString('2099-01-01', $exception->getMessage());
        }
    }
}
