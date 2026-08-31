<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\Protocol;

use Magebit\UniversalCommerce\Api\UniversalCommerceProtocolInterface;
use Magebit\UniversalCommerce\Exception\UcpException;

/**
 * The store speaks one version of the protocol. An agent that asks for another is told so, rather than
 * being served payloads in a shape it is not expecting.
 */
class VersionNegotiator
{
    public const CODE_UNSUPPORTED_VERSION = 'unsupported_version';

    /**
     * @param string|null $agentHeader Value of the request's UCP-Agent header
     * @return void
     * @throws UcpException When the agent asked for a version this store does not speak
     */
    public function assertSupported(?string $agentHeader): void
    {
        $requested = $this->requested($agentHeader);

        if ($requested === null || $requested === UniversalCommerceProtocolInterface::SPEC_VERSION) {
            return;
        }

        throw new UcpException(
            __(
                'This store speaks UCP %1 and cannot serve %2.',
                UniversalCommerceProtocolInterface::SPEC_VERSION,
                $requested
            ),
            self::CODE_UNSUPPORTED_VERSION,
            422
        );
    }

    /**
     * @param string|null $agentHeader Value of the request's UCP-Agent header
     * @return string|null The version it names, or null when it names none
     */
    public function requested(?string $agentHeader): ?string
    {
        if ($agentHeader === null || $agentHeader === '') {
            return null;
        }

        // The parameter is quoted in every example, but an unquoted token is still a valid one.
        if (preg_match('/version=\s*"?([^";\s]+)"?/', $agentHeader, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
