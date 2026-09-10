<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\Spec\Schemas\Shopping;

use Magebit\UcpSpec\Api\Shopping\BuyerConsentResponseBuyerInterface;
use Magebit\UcpSpec\Api\Shopping\BuyerConsentResponseConsentInterface;
use Magebit\UcpSpec\Data\Shopping\Types\Buyer as GeneratedBuyer;
use Magebit\UniversalCommerce\Api\Service\Shopping\BuyerWithConsentInterface;

/**
 * The base buyer plus the consent the `buyer_consent` capability adds to it.
 */
class BuyerWithConsent extends GeneratedBuyer implements BuyerWithConsentInterface
{
    /**
     * @return BuyerConsentResponseConsentInterface|null
     */
    public function getConsent(): ?BuyerConsentResponseConsentInterface
    {
        return $this->instanceOrNull(
            BuyerConsentResponseBuyerInterface::KEY_CONSENT,
            BuyerConsentResponseConsentInterface::class
        );
    }

    /**
     * @param BuyerConsentResponseConsentInterface|null $consent
     * @return self
     */
    public function setConsent(?BuyerConsentResponseConsentInterface $consent): self
    {
        return $this->set(BuyerConsentResponseBuyerInterface::KEY_CONSENT, $consent);
    }
}
