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

use Magebit\AgenticCore\Model\Fulfillment\ShippingOption;
use Magebit\AgenticCore\Model\Fulfillment\ShippingOptionResolver;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterfaceFactory;
use Magebit\UniversalCommerce\Api\Service\Shopping\QuoteValidatorInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;

/**
 * A shippable checkout is not finishable until one of the store's own fulfillment options is chosen,
 * so both the missing choice and a choice the store does not offer are reported to the agent.
 */
class QuoteFulfillmentValidator implements QuoteValidatorInterface
{
    private const PATH_FULFILLMENT = '$.fulfillment';

    private const PATH_SELECTED_OPTION = '$.fulfillment.methods[0].groups[0].selected_option_id';

    /**
     * @param MessageInterfaceFactory $messageFactory
     * @param ShippingOptionResolver $shippingOptionResolver
     */
    public function __construct(
        private readonly MessageInterfaceFactory $messageFactory,
        private readonly ShippingOptionResolver $shippingOptionResolver
    ) {
    }

    /**
     * @param CartInterface $quote
     * @return MessageInterface[]|null
     */
    public function validate(CartInterface $quote): array|null
    {
        /** @var Quote $quote */
        if ($quote->getIsVirtual()) {
            return [];
        }

        $selected = (string) $quote->getShippingAddress()->getShippingMethod();

        if ($selected === '') {
            return [
                $this->createMessage(
                    'A fulfillment option must be selected before this checkout can be completed.',
                    self::PATH_FULFILLMENT
                ),
            ];
        }

        // An unoffered selection is only reported once the store has options of its own to compare it
        // against: with no rates at all the destination is what needs fixing, and it is reported there.
        $offered = array_map(
            fn (ShippingOption $option): string => $option->id,
            $this->shippingOptionResolver->resolve($quote)
        );

        if ($offered === [] || in_array($selected, $offered, true)) {
            return [];
        }

        return [
            $this->createMessage(
                sprintf('"%s" is not a fulfillment option this store offers.', $selected),
                self::PATH_SELECTED_OPTION,
                'invalid'
            ),
        ];
    }

    /**
     * @param string $content
     * @param string $path
     * @param string $code
     * @return MessageInterface
     */
    private function createMessage(string $content, string $path, string $code = 'missing'): MessageInterface
    {
        return $this->messageFactory->create(['data' => [
            MessageInterface::KEY_TYPE => 'error',
            MessageInterface::KEY_PATH => $path,
            MessageInterface::KEY_CODE => $code,
            MessageInterface::KEY_SEVERITY => MessageInterface::SEVERITY_RECOVERABLE,
            MessageInterface::KEY_CONTENT => $content,
        ]]);
    }
}
