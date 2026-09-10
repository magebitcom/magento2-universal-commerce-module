<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Api;

interface UniversalCommerceProtocolInterface
{
    /**
     * The version advertised in discovery, the merchant profile, both capability declarations and
     * every checkout response. It must match the spec target of the installed spec library, which
     * supplies the types those payloads are built from — agents negotiate on this string, so
     * advertising anything else misrepresents what the endpoint actually speaks.
     * `SpecTargetTest` fails if the two drift apart.
     */
    public const SPEC_VERSION = '2026-04-08';
}
