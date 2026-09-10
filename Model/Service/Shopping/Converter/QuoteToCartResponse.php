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

use Magebit\UcpSpec\Api\Shopping\CartResponseInterface;
use Magebit\UcpSpec\Api\Shopping\CartResponseInterfaceFactory;
use Magebit\UcpSpec\Api\UcpResponseCartSchemaInterface;
use Magebit\UcpSpec\Api\UcpResponseCartSchemaInterfaceFactory;
use Magebit\UniversalCommerce\Api\Service\Shopping\QuoteValidatorInterface;
use Magebit\UniversalCommerce\Api\UniversalCommerceProtocolInterface;
use Magebit\UniversalCommerce\Model\Discovery\ServiceRegistry;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\CartInterface;

/**
 * Builds the cart resource response. A cart is a smaller thing than a checkout, not a checkout with
 * fields removed: it has no status, no payment and no fulfillment, and its `ucp` block declares no
 * payment handlers.
 */
class QuoteToCartResponse
{
    /**
     * @param CartResponseInterfaceFactory $cartResponseFactory
     * @param UcpResponseCartSchemaInterfaceFactory $ucpCartFactory
     * @param QuoteToCheckoutResponse $checkoutConverter
     * @param CartMessageDowngrade $messageDowngrade
     * @param QuoteValidatorInterface $quoteValidator
     * @param ServiceRegistry $serviceRegistry
     */
    public function __construct(
        private readonly CartResponseInterfaceFactory $cartResponseFactory,
        private readonly UcpResponseCartSchemaInterfaceFactory $ucpCartFactory,
        private readonly QuoteToCheckoutResponse $checkoutConverter,
        private readonly CartMessageDowngrade $messageDowngrade,
        private readonly QuoteValidatorInterface $quoteValidator,
        private readonly ServiceRegistry $serviceRegistry
    ) {
    }

    /**
     * @param CartInterface $quote
     * @param string $cartId
     * @return CartResponseInterface
     * @throws LocalizedException
     */
    public function convert(CartInterface $quote, string $cartId): CartResponseInterface
    {
        /** @var CartResponseInterface $response */
        $response = $this->cartResponseFactory->create();

        $response->setUcp($this->ucpFor());
        $response->setId($cartId);
        $response->setLineItems($this->checkoutConverter->getLineItems($quote));
        $response->setCurrency($this->checkoutConverter->getCurrency($quote));
        $response->setTotals($this->checkoutConverter->getTotals($quote));
        $response->setLinks($this->checkoutConverter->getLinks($quote));
        $response->setContinueUrl($this->checkoutConverter->getContinueUrl($cartId));

        if ($buyer = $this->checkoutConverter->getBuyer($quote)) {
            $response->setBuyer($buyer);
        }

        if ($expiresAt = $this->checkoutConverter->getExpiresAt($quote)) {
            $response->setExpiresAt($expiresAt);
        }

        /** @var array<int, \Magebit\UcpSpec\Api\Shopping\Types\MessageInterface> $messages */
        $messages = $this->messageDowngrade->apply($this->quoteValidator->validate($quote) ?? []);
        $response->setMessages($messages);

        return $response;
    }

    /**
     * @return UcpResponseCartSchemaInterface
     * @throws LocalizedException
     */
    private function ucpFor(): UcpResponseCartSchemaInterface
    {
        $service = $this->serviceRegistry->getService('dev.ucp.shopping');

        if (!$service) {
            throw new LocalizedException(__('Shopping service not registered'));
        }

        return $this->ucpCartFactory->create([
            'data' => [
                UcpResponseCartSchemaInterface::KEY_VERSION => UniversalCommerceProtocolInterface::SPEC_VERSION,
                UcpResponseCartSchemaInterface::KEY_CAPABILITIES => $service->getCapabilities(),
            ]
        ]);
    }
}
