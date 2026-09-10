<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\Service\Shopping;

use Magento\Framework\App\DeploymentConfig;

/**
 * Origins an agent profile may be fetched from even though the SSRF guard would refuse them — a
 * conformance run or a staging agent on the same network, for instance. Read from app/etc/env.php so
 * it is set per deployment and cannot be turned on from the admin panel.
 */
class TrustedProfileOrigins
{
    /**
     * Where the list is read from in env.php.
     */
    public const CONFIG_PATH = 'universal_commerce/trusted_profile_origins';

    /**
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /**
     * The match is on the whole origin, so trusting one port on one host does not trust the rest of it.
     *
     * @param string $url
     * @return bool
     */
    public function trusts(string $url): bool
    {
        $origin = $this->originOf($url);

        return $origin !== null && in_array($origin, $this->configured(), true);
    }

    /**
     * @return string[]
     */
    private function configured(): array
    {
        $value = $this->deploymentConfig->get(self::CONFIG_PATH);

        if (!is_array($value)) {
            return [];
        }

        $origins = [];

        foreach ($value as $origin) {
            if (is_string($origin) && trim($origin) !== '') {
                $origins[] = strtolower(rtrim(trim($origin), '/'));
            }
        }

        return $origins;
    }

    /**
     * @param string $url
     * @return string|null Scheme, host and port, or null when the url names no host
     */
    private function originOf(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);

        return isset($parts['port']) ? $origin . ':' . $parts['port'] : $origin;
    }
}
