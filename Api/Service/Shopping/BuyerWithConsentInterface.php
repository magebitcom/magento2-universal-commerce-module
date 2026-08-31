<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\UniversalCommerce\Api\Service\Shopping;

use Magebit\UcpSpec\Api\Shopping\BuyerConsentResponseConsentInterface;
use Magebit\UcpSpec\Api\Shopping\Types\BuyerInterface;

/**
 * The buyer plus the consent the `buyer_consent` capability hangs off it. The specification ships that
 * capability as its own schema, so nothing generated composes the two into one type.
 */
interface BuyerWithConsentInterface extends BuyerInterface
{
    /**
     * The consent object is the same four flags in every direction, so the response's type stands for
     * all of them rather than the create and update copies being repeated here.
     *
     * @return \Magebit\UcpSpec\Api\Shopping\BuyerConsentResponseConsentInterface|null
     */
    public function getConsent(): ?BuyerConsentResponseConsentInterface;

    /**
     * @param \Magebit\UcpSpec\Api\Shopping\BuyerConsentResponseConsentInterface|null $consent
     * @return self
     */
    public function setConsent(?BuyerConsentResponseConsentInterface $consent): self;
}
