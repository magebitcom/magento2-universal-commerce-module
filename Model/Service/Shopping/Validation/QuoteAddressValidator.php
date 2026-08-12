<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

namespace Magebit\UniversalCommerce\Model\Service\Shopping\Validation;

use Magebit\AgenticCore\Model\Quote\RegionResolver;
use Magebit\UniversalCommerce\Api\Service\Shopping\QuoteValidatorInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterfaceFactory;

class QuoteAddressValidator implements QuoteValidatorInterface
{
    /**
     * @param MessageInterfaceFactory $messageFactory
     * @param RegionResolver $regionResolver
     */
    public function __construct(
        protected readonly MessageInterfaceFactory $messageFactory,
        protected readonly RegionResolver $regionResolver
    ) {
    }

    /**
     * @param CartInterface $quote
     * @return MessageInterface[]|null
     */
    public function validate(CartInterface $quote): array|null
    {
        /** @var Quote $quote */
        $errors = [];

        $errors = array_merge($errors, $this->validateBillingAddress($quote));
        $errors = array_merge($errors, $this->validateShippingAddress($quote));

        return $errors;
    }

    /**
     * @param CartInterface $quote
     * @return MessageInterface[]
     */
    public function validateBillingAddress(CartInterface $quote): array
    {
        /** @var Quote $quote */
        $billingAddress = $quote->getBillingAddress();

        $errors = [];

        if (!$billingAddress->getFirstName()) {
            $errors[] = $this->createMessage('First name is required', '$.buyer.first_name');
        }

        if (!$billingAddress->getLastName()) {
            $errors[] = $this->createMessage('Last name is required', '$.buyer.last_name');
        }

        if (!$billingAddress->getTelephone()) {
            $errors[] = $this->createMessage('Telephone is required', '$.buyer.phone_number');
        }

        return $errors;
    }

    /**
     * @param CartInterface $quote
     * @return MessageInterface[]
     */
    public function validateShippingAddress(CartInterface $quote): array
    {
        /** @var Quote $quote */
        $shippingAddress = $quote->getShippingAddress();

        if ($quote->getIsVirtual()) {
            return [];
        }

        $errors = [];

        if (!$shippingAddress->getStreet()) {
            $errors[] = $this->createMessage('Street is required', '$.fulfillment.methods[0].destination.street_address');
        }

        if (!$shippingAddress->getCity()) {
            $errors[] = $this->createMessage('City is required', '$.fulfillment.methods[0].destination.address_locality');
        }

        if (!$shippingAddress->getCountry()) {
            $errors[] = $this->createMessage('Country is required', '$.fulfillment.methods[0].destination.address_country');
        }

        if (!$shippingAddress->getPostcode()) {
            $errors[] = $this->createMessage('Postcode is required', '$.fulfillment.methods[0].destination.postal_code');
        }

        $errors = array_merge($errors, $this->validateRegion($shippingAddress));

        return $errors;
    }

    /**
     * Most countries have no regions, so a region is demanded only where the store says one is needed —
     * and where one arrived, it has to name a region the country actually has, since an unresolvable
     * name would otherwise surface as a failure at completion rather than as a message here.
     *
     * @param Address $address
     * @return MessageInterface[]
     */
    private function validateRegion(Address $address): array
    {
        $path = '$.fulfillment.methods[0].destination.address_region';
        $countryId = (string) $address->getCountryId();
        $region = $address->getData('region');
        $region = is_string($region) ? $region : '';

        if ($countryId === '' || !$this->regionResolver->isRequiredFor($countryId)) {
            return [];
        }

        if ($region === '') {
            return [$this->createMessage('Region is required', $path)];
        }

        if ($this->regionResolver->resolve($countryId, $region) === null) {
            return [
                $this->createMessage(
                    sprintf('"%s" is not a region of %s.', $region, $countryId),
                    $path,
                    'invalid'
                ),
            ];
        }

        return [];
    }

    /**
     * @param string $message
     * @param string $path
     * @param string $code
     * @param string $severity
     * @return MessageInterface
     */
    public function createMessage(
        string $message,
        string $path,
        string $code = 'missing',
        string $severity = MessageInterface::SEVERITY_REQUIRES_BUYER_INPUT
    ): MessageInterface {
        return $this->messageFactory->create([ 'data' => [
            'type' => 'error',
            'path' => $path,
            'code' => $code,
            'severity' => $severity,
            'content' => $message,
        ]]);
    }
}
