<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\Service\Shopping\Validation;

use Magebit\AgenticCore\Model\Quote\ReadinessCheck;
use Magebit\AgenticCore\Model\Quote\Requirement;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterfaceFactory;
use Magebit\UniversalCommerce\Api\Service\Shopping\QuoteValidatorInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;

/**
 * Reports what the quote still needs, in this protocol's words. What is missing is decided by the
 * shared check; only the wording and the path each thing reports at belong here.
 */
class QuoteAddressValidator implements QuoteValidatorInterface
{
    /**
     * Where this protocol carries each field, and what to say when it is absent. The buyer's own
     * details sit at the top level; the address sits inside the fulfillment method's destination.
     */
    private const DESTINATION = '$.fulfillment.methods[0].destination';

    private const REPORTS = [
        'FirstName' => ['First name is required', '$.buyer.first_name'],
        'LastName' => ['Last name is required', '$.buyer.last_name'],
        'Email' => ['An email address is required', '$.buyer.email'],
        'PhoneNumber' => ['Telephone is required', '$.buyer.phone_number'],
        'Street' => ['Street is required', self::DESTINATION . '.street_address'],
        'City' => ['City is required', self::DESTINATION . '.address_locality'],
        'Country' => ['Country is required', self::DESTINATION . '.address_country'],
        'Postcode' => ['Postcode is required', self::DESTINATION . '.postal_code'],
        'Region' => ['Region is required', self::DESTINATION . '.address_region'],
    ];

    /**
     * @param MessageInterfaceFactory $messageFactory
     * @param ReadinessCheck $readinessCheck
     */
    public function __construct(
        protected readonly MessageInterfaceFactory $messageFactory,
        protected readonly ReadinessCheck $readinessCheck
    ) {
    }

    /**
     * @param CartInterface $quote
     * @return MessageInterface[]|null
     */
    public function validate(CartInterface $quote): array|null
    {
        /** @var Quote $quote */
        $missing = $this->readinessCheck->contact($quote->getBillingAddress());

        if (!$quote->getCustomerEmail()) {
            $missing[] = Requirement::Email;
        }

        if (!$quote->getIsVirtual()) {
            $missing = array_merge($missing, $this->readinessCheck->postal($quote->getShippingAddress()));
        }

        return $this->report(array_unique($missing, SORT_REGULAR), $quote);
    }

    /**
     * @param Requirement[] $missing
     * @param Quote $quote
     * @return MessageInterface[]
     */
    private function report(array $missing, Quote $quote): array
    {
        $messages = [];

        foreach ($missing as $requirement) {
            if ($requirement === Requirement::UnknownRegion) {
                $messages[] = $this->unknownRegion($quote);

                continue;
            }

            [$content, $path] = self::REPORTS[$requirement->name];
            $messages[] = $this->createMessage($content, $path);
        }

        return $messages;
    }

    /**
     * @param Quote $quote
     * @return MessageInterface
     */
    private function unknownRegion(Quote $quote): MessageInterface
    {
        $address = $quote->getShippingAddress();
        $region = $address->getData('region');

        return $this->createMessage(
            sprintf(
                '"%s" is not a region of %s.',
                is_string($region) ? $region : '',
                (string) $address->getCountryId()
            ),
            self::DESTINATION . '.address_region',
            'invalid'
        );
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
