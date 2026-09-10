<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit\Api;

use Magebit\UcpSpec\Runtime\SpecObject;
use Magebit\UniversalCommerce\Api\UniversalCommerceProtocolInterface;
use PHPUnit\Framework\TestCase;

/**
 * The module advertises a protocol version and builds its payloads from the spec library's generated
 * types. If the two disagree, the endpoint tells agents it speaks a version it does not.
 */
class SpecTargetTest extends TestCase
{
    /**
     * @return void
     */
    public function testTheAdvertisedVersionMatchesTheInstalledSpecTarget(): void
    {
        $manifest = $this->manifest();

        $this->assertSame(
            $manifest['spec']['target'] ?? null,
            UniversalCommerceProtocolInterface::SPEC_VERSION,
            'The advertised SPEC_VERSION and the installed spec library target have drifted apart.'
        );
    }

    /**
     * A target with no recorded upstream commit cannot be reproduced, so a release must not carry one.
     *
     * @return void
     */
    public function testTheInstalledSpecTargetRecordsItsUpstreamCommit(): void
    {
        $upstream = $this->manifest()['upstream'] ?? [];

        $this->assertNotEmpty($upstream['repository'] ?? null);
        $this->assertNotEmpty($upstream['ref'] ?? null);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', (string) ($upstream['commit'] ?? ''));
    }

    /**
     * Located through the library's own runtime class rather than a hardcoded vendor path, so this
     * still resolves wherever the package is installed.
     *
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $runtime = (new \ReflectionClass(SpecObject::class))->getFileName();

        $this->assertIsString($runtime);

        $path = dirname($runtime, 2) . '/spec.manifest.json';

        $this->assertFileExists($path, 'The spec library ships no manifest to compare against.');

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode((string) file_get_contents($path), true);

        return $manifest;
    }
}
