<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit\Model\Service\Shopping;

use Magebit\UniversalCommerce\Model\Service\Shopping\TrustedProfileOrigins;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;

class TrustedProfileOriginsTest extends TestCase
{
    /**
     * @param mixed $configured
     * @param string $url
     * @param bool $expected
     * @return void
     * @dataProvider originProvider
     */
    public function testAnOriginIsTrustedOnlyWhenTheDeploymentNamedIt(
        mixed $configured,
        string $url,
        bool $expected
    ): void {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')
            ->with(TrustedProfileOrigins::CONFIG_PATH)
            ->willReturn($configured);

        $this->assertSame($expected, (new TrustedProfileOrigins($deploymentConfig))->trusts($url));
    }

    /**
     * @return array<string, array{mixed, string, bool}>
     */
    public static function originProvider(): array
    {
        $localhost = ['http://localhost:8285'];

        return [
            'nothing configured' => [null, 'http://localhost:8285/p.json', false],
            'not a list' => ['http://localhost:8285', 'http://localhost:8285/p.json', false],
            'exact match' => [$localhost, 'http://localhost:8285/p.json', true],
            'trailing slash configured' => [
                ['http://localhost:8285/'],
                'http://localhost:8285/p.json',
                true,
            ],
            'different case' => [$localhost, 'HTTP://LOCALHOST:8285/p.json', true],
            'another port on the same host' => [$localhost, 'http://localhost:9000/p.json', false],
            'another scheme' => [$localhost, 'https://localhost:8285/p.json', false],
            'another host' => [$localhost, 'http://evil.test:8285/p.json', false],
            'no port when one was named' => [$localhost, 'http://localhost/p.json', false],
            'not a url' => [$localhost, 'not-a-url', false],
            'blank entries ignored' => [['', '   '], 'http://localhost:8285/p.json', false],
        ];
    }
}
