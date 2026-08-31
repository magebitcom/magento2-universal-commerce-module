<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\Simulation;

use Magento\Framework\App\DeploymentConfig;

/**
 * Reads the shared secret that guards the conformance suite's simulation endpoint. It lives in
 * app/etc/env.php so it is set per deployment and never appears in the admin panel or in config.xml.
 */
class Secret
{
    /**
     * Where the secret is read from in env.php.
     */
    public const CONFIG_PATH = 'universal_commerce/simulation_secret';

    /**
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /**
     * No secret in env.php means the simulation endpoint is switched off entirely.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return $this->value() !== null;
    }

    /**
     * @param string|null $candidate Value the caller sent
     * @return bool
     */
    public function matches(?string $candidate): bool
    {
        $secret = $this->value();

        if ($secret === null || $candidate === null || $candidate === '') {
            return false;
        }

        // hash_equals takes the same time whichever byte differs, so the comparison cannot be used to
        // guess the secret one character at a time.
        return hash_equals($secret, $candidate);
    }

    /**
     * @return string|null
     */
    private function value(): ?string
    {
        $value = $this->deploymentConfig->get(self::CONFIG_PATH);

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
