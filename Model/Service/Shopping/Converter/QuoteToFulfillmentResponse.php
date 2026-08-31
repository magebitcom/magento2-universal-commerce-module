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

use Magebit\AgenticCore\Model\Fulfillment\ShippingOption;
use Magebit\AgenticCore\Model\Fulfillment\ShippingOptionResolver;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentResponseInterface;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentMethodResponseInterface;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentMethodResponseInterfaceFactory;
use Magento\Quote\Model\Quote\Address;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentGroupResponseInterface;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentGroupResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentOptionResponseInterface;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentOptionResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentDestinationResponseInterface;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentDestinationResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\TotalResponseInterface;
use Magebit\UniversalCommerce\Api\Data\TotalTypeInterface;
use Magebit\UcpSpec\Api\Shopping\Types\TotalResponseInterfaceFactory;

class QuoteToFulfillmentResponse
{
    /**
     * Identifiers used only when the agent submitted none of its own.
     */
    private const DEFAULT_METHOD_ID = 'shipping';
    private const DEFAULT_GROUP_ID = 'package';

    /**
     * @param FulfillmentResponseInterfaceFactory $fulfillmentResponseFactory
     * @param FulfillmentMethodResponseInterfaceFactory $fulfillmentMethodResponseFactory
     * @param FulfillmentGroupResponseInterfaceFactory $fulfillmentGroupResponseFactory
     * @param FulfillmentOptionResponseInterfaceFactory $fulfillmentOptionResponseFactory
     * @param FulfillmentDestinationResponseInterfaceFactory $fulfillmentDestinationResponseFactory
     * @param TotalResponseInterfaceFactory $totalResponseFactory
     * @param ShippingOptionResolver $shippingOptionResolver
     */
    public function __construct(
        protected readonly FulfillmentResponseInterfaceFactory $fulfillmentResponseFactory,
        protected readonly FulfillmentMethodResponseInterfaceFactory $fulfillmentMethodResponseFactory,
        protected readonly FulfillmentGroupResponseInterfaceFactory $fulfillmentGroupResponseFactory,
        protected readonly FulfillmentOptionResponseInterfaceFactory $fulfillmentOptionResponseFactory,
        protected readonly FulfillmentDestinationResponseInterfaceFactory $fulfillmentDestinationResponseFactory,
        protected readonly TotalResponseInterfaceFactory $totalResponseFactory,
        protected readonly ShippingOptionResolver $shippingOptionResolver
    ) {
    }

    /**
     * @param CartInterface $quote
     * @param array<mixed>|null $submitted Fulfillment tree as the agent submitted it
     * @return FulfillmentResponseInterface|null
     */
    public function convert(CartInterface $quote, ?array $submitted = null): ?FulfillmentResponseInterface
    {
        /** @var Quote $quote */
        if ($quote->getIsVirtual()) {
            return null;
        }

        $shippingAddress = $quote->getShippingAddress();

        if (!$shippingAddress) {
            return null;
        }

        // The shared resolver collects the rates; nothing here needs to prompt it.
        $quoteItemIds = array_map(function (Quote\Item $quoteItem) {
            return (string) $quoteItem->getId();
        }, $quote->getAllItems());

        /** @var FulfillmentResponseInterface $response */
        $response = $this->fulfillmentResponseFactory->create();

        $methods = $this->getMethods($shippingAddress, $quoteItemIds, $this->firstOf($submitted, 'methods'));
        $response->setMethods($methods);
        return $response;
    }

    /**
     * @param Address $shippingAddress
     * @param array<string> $quoteItemIds
     * @param array<mixed>|null $submittedMethod Method as the agent submitted it
     * @return FulfillmentMethodResponseInterface[]
     */
    public function getMethods(Address $shippingAddress, array $quoteItemIds, ?array $submittedMethod = null): array
    {
        // Reported even with nothing to offer yet: an agent that submitted a method with no destination
        // has to see it come back, or it cannot tell whether the store understood the request.
        $shippingOptions = $this->shippingOptionResolver->resolve($shippingAddress->getQuote());
        $submittedGroup = $this->firstOf($submittedMethod, 'groups');

        /** @var FulfillmentMethodResponseInterface $method */
        $method = $this->fulfillmentMethodResponseFactory->create();
        $method->setId($this->submittedString($submittedMethod, 'id') ?? self::DEFAULT_METHOD_ID);
        $method->setType(
            $this->submittedString($submittedMethod, 'type') ?? FulfillmentMethodResponseInterface::TYPE_SHIPPING
        );
        $method->setLineItemIds($quoteItemIds);

        // The destination keeps the identifier the agent gave it, so the selection below names something
        // the response actually lists. Renaming it to the quote address id left the reference dangling.
        $submittedDestination = $this->firstOf($submittedMethod, 'destinations');
        $destination = $this->convertAddressToDestination(
            $shippingAddress,
            $this->submittedString($submittedDestination, 'id')
        );

        if ($destination) {
            $method->setDestinations([$destination]);
            $method->setSelectedDestinationId($destination->getId());
        }

        $group = $this->fulfillmentGroupResponseFactory->create();
        $group->setId($this->submittedString($submittedGroup, 'id') ?? self::DEFAULT_GROUP_ID);
        $group->setLineItemIds($quoteItemIds);

        $options = $this->convertOptions($shippingOptions);
        if (!empty($options)) {
            $group->setOptions(array_values($options));

            // Only a selection that names one of the options above is reported. Echoing back an id the
            // store does not offer leaves a dangling reference, and tells the agent a shipping method is
            // chosen when the order has none.
            $selectedOptionId = $this->firstOfferedOption(
                $options,
                $this->submittedString($submittedGroup, 'selected_option_id'),
                $shippingAddress->getShippingMethod()
            );

            if ($selectedOptionId !== null) {
                $group->setSelectedOptionId($selectedOptionId);
            }
        }

        $method->setGroups([$group]);

        return [$method];
    }

    /**
     * @param array<mixed>|null $submitted Fragment of the submitted tree
     * @param string $key List field to read
     * @return array<mixed>|null First element of that list, when it is an object
     */
    private function firstOf(?array $submitted, string $key): ?array
    {
        $list = $submitted[$key] ?? null;

        if (!is_array($list)) {
            return null;
        }

        $first = $list[0] ?? null;

        return is_array($first) ? $first : null;
    }

    /**
     * @param array<mixed>|null $submitted Fragment of the submitted tree
     * @param string $key Field to read
     * @return string|null Non-empty submitted value, or null to fall back
     */
    private function submittedString(?array $submitted, string $key): ?string
    {
        $value = $submitted[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param Address $address
     * @param string|null $submittedId Identifier the agent gave this destination, when it named one
     * @return FulfillmentDestinationResponseInterface|null
     */
    private function convertAddressToDestination(
        Address $address,
        ?string $submittedId = null
    ): ?FulfillmentDestinationResponseInterface {
        if (!$address->getCountryId()) {
            return null;
        }

        $street = $address->getStreet();
        $streetAddress = is_array($street) ? ($street[0] ?? '') : (string) $street;

        /** @var FulfillmentDestinationResponseInterface $destination */
        $destination = $this->fulfillmentDestinationResponseFactory->create();
        $destination->setId($submittedId ?? (string) $address->getId());

        if ($streetAddress) {
            $destination->setStreetAddress($streetAddress);
        }

        if ($address->getCity()) {
            $destination->setAddressLocality($address->getCity());
        }
        if ($address->getRegion()) {
            $destination->setAddressRegion($address->getRegion());
        }
        if ($address->getCountryId()) {
            $destination->setAddressCountry($address->getCountryId());
        }
        if ($address->getPostcode()) {
            $destination->setPostalCode($address->getPostcode());
        }
        if ($address->getFirstname()) {
            $destination->setFirstName($address->getFirstname());
        }
        if ($address->getLastname()) {
            $destination->setLastName($address->getLastname());
        }
        if ($address->getTelephone()) {
            $destination->setPhoneNumber($address->getTelephone());
        }

        return $destination;
    }

    /**
     * @param FulfillmentOptionResponseInterface[] $options Options the response lists
     * @param string|null ...$candidates Selections to try, best first
     * @return string|null The first candidate the store actually offers
     */
    private function firstOfferedOption(array $options, ?string ...$candidates): ?string
    {
        $offered = [];

        foreach ($options as $option) {
            $offered[] = (string) $option->getId();
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '' && in_array($candidate, $offered, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * A breakdown that adds up: the shipping charge, its tax where there is any, and the total the
     * option adds to the order. A consumer reading only the total still gets the full cost.
     *
     * @param ShippingOption[] $shippingOptions
     * @return FulfillmentOptionResponseInterface[]
     */
    private function convertOptions(array $shippingOptions): array
    {
        return array_map(function (ShippingOption $shippingOption) {
            /** @var FulfillmentOptionResponseInterface $option */
            $option = $this->fulfillmentOptionResponseFactory->create();
            $option->setId($shippingOption->id);
            $option->setTitle($shippingOption->title);
            $option->setDescription($shippingOption->description);
            $option->setCarrier($shippingOption->carrier);
            $option->setTotals($this->optionTotals($shippingOption));

            return $option;
        }, $shippingOptions);
    }

    /**
     * @param ShippingOption $shippingOption
     * @return TotalResponseInterface[]
     */
    private function optionTotals(ShippingOption $shippingOption): array
    {
        $totals = [
            $this->total(
                TotalTypeInterface::TYPE_FULFILLMENT,
                $shippingOption->title,
                $shippingOption->amountExclTax
            ),
        ];

        if ($shippingOption->taxAmount > 0) {
            $totals[] = $this->total(TotalTypeInterface::TYPE_TAX, 'Tax', $shippingOption->taxAmount);
        }

        $totals[] = $this->total(
            TotalTypeInterface::TYPE_TOTAL,
            $shippingOption->title,
            $shippingOption->amountInclTax
        );

        return $totals;
    }

    /**
     * @param string $type
     * @param string $displayText
     * @param int $amount Minor units
     * @return TotalResponseInterface
     */
    private function total(string $type, string $displayText, int $amount): TotalResponseInterface
    {
        /** @var TotalResponseInterface $total */
        $total = $this->totalResponseFactory->create();
        $total->setType($type);
        $total->setAmount($amount);
        $total->setDisplayText($displayText);

        return $total;
    }
}
