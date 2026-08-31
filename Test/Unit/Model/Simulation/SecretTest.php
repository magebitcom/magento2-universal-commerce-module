<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit\Model\Simulation;

use Magebit\UniversalCommerce\Model\Simulation\Secret;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;

class SecretTest extends TestCase
{
    private const SECRET = 'super-secret-sim-key';

    /**
     * @return void
     */
    public function testASecretInEnvSwitchesTheEndpointOn(): void
    {
        $this->assertTrue($this->secret(self::SECRET)->isConfigured());
    }

    /**
     * A store that never opted in must not expose the endpoint at all.
     *
     * @return void
     */
    public function testNoSecretLeavesTheEndpointOff(): void
    {
        $this->assertFalse($this->secret(null)->isConfigured());
    }

    /**
     * @return void
     */
    public function testWhitespaceIsNotASecret(): void
    {
        $this->assertFalse($this->secret('   ')->isConfigured());
    }

    /**
     * @return void
     */
    public function testANonStringValueIsIgnored(): void
    {
        $this->assertFalse($this->secret(['not-a-secret'])->isConfigured());
    }

    /**
     * @return void
     */
    public function testTheMatchingSecretIsAccepted(): void
    {
        $this->assertTrue($this->secret(self::SECRET)->matches(self::SECRET));
    }

    /**
     * @return void
     */
    public function testAWrongSecretIsRejected(): void
    {
        $this->assertFalse($this->secret(self::SECRET)->matches('for-sure-incorrect-secret'));
    }

    /**
     * @return void
     */
    public function testAMissingSecretIsRejected(): void
    {
        $this->assertFalse($this->secret(self::SECRET)->matches(null));
        $this->assertFalse($this->secret(self::SECRET)->matches(''));
    }

    /**
     * With nothing configured, nothing the caller sends can be a match — an empty header included.
     *
     * @return void
     */
    public function testNothingMatchesWhenNoSecretIsConfigured(): void
    {
        $this->assertFalse($this->secret(null)->matches(''));
        $this->assertFalse($this->secret(null)->matches('anything'));
    }

    /**
     * @param mixed $configured Value env.php holds
     * @return Secret
     */
    private function secret(mixed $configured): Secret
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')
            ->with(Secret::CONFIG_PATH)
            ->willReturn($configured);

        return new Secret($deploymentConfig);
    }
}
