<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\Service\Shopping\Converter;

use Magebit\UcpSpec\Api\Shopping\Types\PostalAddressInterface;
use Magebit\UcpSpec\Api\Shopping\Types\PostalAddressInterfaceFactory;
use Magento\Sales\Api\Data\OrderAddressInterface;

/**
 * An order address as the spec's postal address. Every field is optional, so a field the address has no
 * value for is left out rather than sent as an empty string.
 */
class OrderAddressToPostalAddress
{
    /**
     * @param PostalAddressInterfaceFactory $postalAddressFactory
     */
    public function __construct(
        private readonly PostalAddressInterfaceFactory $postalAddressFactory
    ) {
    }

    /**
     * @param OrderAddressInterface $address
     * @return PostalAddressInterface
     */
    public function convert(OrderAddressInterface $address): PostalAddressInterface
    {
        $street = $address->getStreet() ?? [];

        /** @var PostalAddressInterface $postalAddress */
        $postalAddress = $this->postalAddressFactory->create();

        if ($this->present($street[0] ?? null)) {
            $postalAddress->setStreetAddress((string) $street[0]);
        }

        if ($this->present($street[1] ?? null)) {
            $postalAddress->setExtendedAddress((string) $street[1]);
        }

        if ($this->present($address->getCity())) {
            $postalAddress->setAddressLocality((string) $address->getCity());
        }

        if ($this->present($address->getRegion())) {
            $postalAddress->setAddressRegion((string) $address->getRegion());
        }

        if ($this->present($address->getCountryId())) {
            $postalAddress->setAddressCountry((string) $address->getCountryId());
        }

        if ($this->present($address->getPostcode())) {
            $postalAddress->setPostalCode((string) $address->getPostcode());
        }

        if ($this->present($address->getFirstname())) {
            $postalAddress->setFirstName((string) $address->getFirstname());
        }

        if ($this->present($address->getLastname())) {
            $postalAddress->setLastName((string) $address->getLastname());
        }

        if ($this->present($address->getTelephone())) {
            $postalAddress->setPhoneNumber((string) $address->getTelephone());
        }

        return $postalAddress;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function present(mixed $value): bool
    {
        return is_scalar($value) && (string) $value !== '';
    }
}
