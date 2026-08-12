<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\Service\Shopping;

use Magebit\UniversalCommerce\Api\Service\Shopping\RestHandlerInterface;
use Magebit\UniversalCommerce\Api\Service\Shopping\CheckoutUpdateRequestInterface;
use Magebit\UniversalCommerce\Api\Service\Shopping\CheckoutCreateRequestInterface;
use Magebit\UniversalCommerce\Api\Service\Shopping\CheckoutResponseInterface;
use Magebit\UcpSpec\Api\Shopping\PaymentInterface;
use Magebit\UcpSpec\Api\Shopping\Types\FulfillmentRequestInterface;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\QuoteToCheckoutResponse;
use Magebit\UniversalCommerce\Model\Service\Shopping\CheckoutDataProcessor;
use Magento\Quote\Api\GuestCartManagementInterface;
use Magento\Quote\Api\GuestCartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magebit\UniversalCommerce\Exception\UcpException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magebit\UniversalCommerce\Api\CheckoutMetaRepositoryInterface;
use Magebit\UniversalCommerce\Api\Data\CheckoutMetaInterface;
use Magebit\UniversalCommerce\Api\Data\CheckoutMetaInterfaceFactory;
use Magebit\AgenticCore\Api\OrderLinkRepositoryInterface;
use Magebit\UniversalCommerce\Model\Config;
use Magebit\UniversalCommerce\Model\IdempotencyHandler;
use Magento\Quote\Model\Quote;

class RestHandler implements RestHandlerInterface
{
    /**
     * @param CheckoutDataProcessor $checkoutDataProcessor
     * @param GuestCartManagementInterface $guestCartManagement
     * @param GuestCartRepositoryInterface $guestCartRepository
     * @param QuoteToCheckoutResponse $quoteToCheckoutResponse
     * @param CartRepositoryInterface $cartRepository
     * @param CheckoutMetaRepositoryInterface $checkoutMetaRepository
     * @param CheckoutMetaInterfaceFactory $checkoutMetaFactory
     * @param Config $config
     * @param OrderLinkRepositoryInterface $orderLinkRepository
     */
    public function __construct(
        protected readonly CheckoutDataProcessor $checkoutDataProcessor,
        protected readonly GuestCartManagementInterface $guestCartManagement,
        protected readonly GuestCartRepositoryInterface $guestCartRepository,
        protected readonly QuoteToCheckoutResponse $quoteToCheckoutResponse,
        protected readonly CartRepositoryInterface $cartRepository,
        protected readonly CheckoutMetaRepositoryInterface $checkoutMetaRepository,
        protected readonly CheckoutMetaInterfaceFactory $checkoutMetaFactory,
        protected readonly Config $config,
        protected readonly OrderLinkRepositoryInterface $orderLinkRepository
    ) {
    }

    /**
     * @param CheckoutCreateRequestInterface $request
     * @return CheckoutResponseInterface
     */
    public function createCheckout(CheckoutCreateRequestInterface $request): CheckoutResponseInterface
    {
        $maskedCartId = $this->guestCartManagement->createEmptyCart();
        $cart = $this->guestCartRepository->get($maskedCartId);

        $this->checkoutDataProcessor->processCreateCheckoutRequest($cart, $request, $maskedCartId);
        $this->cartRepository->save($cart);
        $this->recordCheckoutMeta($maskedCartId, (int) $cart->getId(), $request->getFulfillment());

        return $this->quoteToCheckoutResponse->convert($cart, $maskedCartId);
    }

    /**
     * @param string $checkoutId
     * @return CheckoutResponseInterface
     * @throws LocalizedException
     */
    public function getCheckout(string $checkoutId): CheckoutResponseInterface
    {
        $cart = $this->getCartByMaskedId($checkoutId);
        return $this->quoteToCheckoutResponse->convert($cart, $checkoutId);
    }

    /**
     * @param string $checkoutId
     * @return CheckoutResponseInterface
     * @throws LocalizedException
     */
    public function cancelCheckout(string $checkoutId): CheckoutResponseInterface
    {
        $cart = $this->getCartByMaskedId($checkoutId);

        if (!$cart->getIsActive()) {
            throw new UcpException(
                __('Checkout session is already canceled: %1.', $checkoutId),
                'error',
                'checkout_already_canceled',
                400
            );
        }

        $cart->setIsActive(false);
        $this->cartRepository->save($cart);
        return $this->quoteToCheckoutResponse->convert($cart, $checkoutId);
    }

    /**
     * @param string $checkoutId
     * @param CheckoutUpdateRequestInterface $request
     * @return CheckoutResponseInterface
     * @throws LocalizedException
     */
    public function updateCheckout(string $checkoutId, CheckoutUpdateRequestInterface $request): CheckoutResponseInterface
    {
        $cart = $this->getCartByMaskedId($checkoutId);
        $this->checkoutDataProcessor->processUpdateCheckoutRequest($cart, $request, $checkoutId);
        $this->cartRepository->save($cart);
        $this->rememberSubmittedFulfillment($checkoutId, $request->getFulfillment());

        return $this->quoteToCheckoutResponse->convert($cart, $checkoutId);
    }

    /**
     * @param string $checkoutId
     * @param PaymentInterface $paymentData
     * @return CheckoutResponseInterface
     * @throws LocalizedException
     */
    public function completeCheckout(string $checkoutId, PaymentInterface $paymentData): CheckoutResponseInterface
    {
        $cart = $this->getCartByMaskedId($checkoutId);
        $billingAddress = null;

        foreach ($paymentData->getInstruments() ?? [] as $instrument) {
            if ($instrument->getBillingAddress() !== null) {
                $billingAddress = $instrument->getBillingAddress();
                break;
            }
        }

        if ($billingAddress) {
            $this->checkoutDataProcessor->processBillingAddress($cart, $billingAddress);
        }

        /** @var Quote $cart */
        $cart->getPayment()->setMethod($this->config->getPaymentMethod((int) $cart->getStoreId()));
        $this->cartRepository->save($cart);

        try {
            $orderId = (int) $this->guestCartManagement->placeOrder($checkoutId);
        } catch (LocalizedException $e) {
            throw new LocalizedException(__('Failed to place order: %1', $e->getMessage()));
        }

        $this->linkOrder($checkoutId, $orderId);

        return $this->quoteToCheckoutResponse->convert($cart, $checkoutId);
    }

    /**
     * Get cart by masked ID
     *
     * @param string $maskedCartId
     * @return CartInterface
     * @throws UcpException
     */
    public function getCartByMaskedId(string $maskedCartId): CartInterface
    {
        try {
            $cart = $this->guestCartRepository->get($maskedCartId);
        } catch (NoSuchEntityException $e) {
            throw new UcpException(
                __('Checkout session not found: %1. Please create a new checkout session.', $maskedCartId),
                'not_found',
                'session_not_found',
                404
            );
        }

        return $cart;
    }

    /**
     * @param string $checkoutId
     * @param int $quoteId
     * @return void
     */
    protected function recordCheckoutMeta(
        string $checkoutId,
        int $quoteId,
        ?FulfillmentRequestInterface $fulfillment = null
    ): void {
        /** @var CheckoutMetaInterface $meta */
        $meta = $this->checkoutMetaFactory->create();
        $meta->setCheckoutId($checkoutId);
        $meta->setQuoteId($quoteId);
        $meta->setSubmittedFulfillment($this->encodeFulfillment($fulfillment));

        $this->checkoutMetaRepository->save($meta);
    }

    /**
     * The response has to echo the ids the agent chose, so the tree is kept as submitted rather than
     * rebuilt from the quote.
     *
     * @param string $checkoutId
     * @param FulfillmentRequestInterface|null $fulfillment
     * @return void
     */
    protected function rememberSubmittedFulfillment(string $checkoutId, ?FulfillmentRequestInterface $fulfillment): void
    {
        if ($fulfillment === null) {
            return;
        }

        try {
            $meta = $this->checkoutMetaRepository->getByCheckoutId($checkoutId);
        } catch (NoSuchEntityException $exception) {
            return;
        }

        $meta->setSubmittedFulfillment($this->encodeFulfillment($fulfillment));
        $this->checkoutMetaRepository->save($meta);
    }

    /**
     * @param FulfillmentRequestInterface|null $fulfillment
     * @return string|null
     */
    private function encodeFulfillment(?FulfillmentRequestInterface $fulfillment): ?string
    {
        if ($fulfillment === null) {
            return null;
        }

        $encoded = json_encode($fulfillment);

        return $encoded === false ? null : $encoded;
    }

    /**
     * @param string $checkoutId
     * @param int $orderId
     * @return void
     */
    protected function linkOrder(string $checkoutId, int $orderId): void
    {
        try {
            $meta = $this->checkoutMetaRepository->getByCheckoutId($checkoutId);
        } catch (NoSuchEntityException $exception) {
            /** @var CheckoutMetaInterface $meta */
            $meta = $this->checkoutMetaFactory->create();
            $meta->setCheckoutId($checkoutId);
        }

        $meta->setOrderId($orderId);
        $this->checkoutMetaRepository->save($meta);

        // The shared link is what status resolution reads; the meta row keeps the protocol-specific
        // fields and its own order_id stays written until the contract release drops it.
        $this->orderLinkRepository->link(
            IdempotencyHandler::SCOPE,
            $checkoutId,
            (int) $meta->getQuoteId(),
            $orderId
        );
    }
}
