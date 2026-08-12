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

use Magebit\AgenticCore\Model\Buyer\BuyerResolver;
use Magebit\UcpSpec\Api\Shopping\Types\BuyerInterface;
use Magebit\UcpSpec\Api\Shopping\Types\BuyerInterfaceFactory;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;

class QuoteToBuyerResponse
{
    /**
     * @param BuyerInterfaceFactory $buyerInterfaceFactory
     * @param BuyerResolver $buyerResolver
     */
    public function __construct(
        protected readonly BuyerInterfaceFactory $buyerInterfaceFactory,
        protected readonly BuyerResolver $buyerResolver,
    ) {
    }

    /**
     * @param CartInterface $quote
     * @return BuyerInterface|null
     */
    public function convert(CartInterface $quote): ?BuyerInterface
    {
        /** @var Quote $quote */
        $identity = $this->buyerResolver->resolve($quote);

        // An all-empty buyer is omitted rather than sent as an empty object.
        if ($identity->isEmpty()) {
            return null;
        }

        /** @var BuyerInterface $buyer */
        $buyer = $this->buyerInterfaceFactory->create();

        if ($identity->firstName !== null) {
            $buyer->setFirstName($identity->firstName);
        }

        if ($identity->lastName !== null) {
            $buyer->setLastName($identity->lastName);
        }

        if ($identity->email !== null) {
            $buyer->setEmail($identity->email);
        }

        if ($identity->phoneNumber !== null) {
            $buyer->setPhoneNumber($identity->phoneNumber);
        }

        return $buyer;
    }
}
