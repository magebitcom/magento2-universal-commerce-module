<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\Service\Shopping\Capability;

use Magebit\UcpSpec\Data\CapabilityBusinessSchema;
use Magebit\UniversalCommerce\Api\UniversalCommerceProtocolInterface;

class CartCapability extends CapabilityBusinessSchema
{
    /**
     * @return string
     */
    public function getVersion(): string
    {
        return UniversalCommerceProtocolInterface::SPEC_VERSION;
    }

    /**
     * @return string
     */
    public function getSpec(): string
    {
        return 'https://ucp.dev/specs/shopping/cart';
    }

    /**
     * @return string
     */
    public function getSchema(): string
    {
        return 'https://ucp.dev/schemas/shopping/cart.json';
    }

    /**
     * @return string|null
     */
    public function getId(): string|null
    {
        return null;
    }

    /**
     * @return array<mixed>|null
     */
    public function getConfig(): array|null
    {
        return null;
    }

    /**
     * @return string|null
     */
    public function getExtends(): string|null
    {
        return null;
    }
}
