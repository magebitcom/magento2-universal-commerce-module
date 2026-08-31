<?php

/**
 * @author Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license https://magebit.com/code-license
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Api\Data;

/**
 * Interface for Checkout Meta Model
 * Provides methods to manage checkout metadata records
 */
interface CheckoutMetaInterface
{
    public const ENTITY_ID = 'entity_id';
    public const CHECKOUT_ID = 'checkout_id';
    public const QUOTE_ID = 'quote_id';
    public const ORDER_ID = 'order_id';
    public const WEBHOOK_URL = 'webhook_url';
    public const SUBMITTED_FULFILLMENT = 'submitted_fulfillment';
    public const BUYER_CONSENT = 'buyer_consent';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    /**
     * Get entity ID
     *
     * @return int|null
     */
    public function getEntityId(): ?int;

    /**
     * Get checkout ID
     *
     * @return string|null
     */
    public function getCheckoutId(): ?string;

    /**
     * Set checkout ID
     *
     * @param string $checkoutId
     * @return $this
     */
    public function setCheckoutId(string $checkoutId): self;

    /**
     * Get quote ID
     *
     * @return int|null
     */
    public function getQuoteId(): ?int;

    /**
     * Set quote ID
     *
     * @param int $quoteId
     * @return $this
     */
    public function setQuoteId(int $quoteId): self;

    /**
     * Get order ID
     *
     * @return int|null
     */
    public function getOrderId(): ?int;

    /**
     * Set order ID
     *
     * @param int|null $orderId
     * @return $this
     */
    public function setOrderId(?int $orderId): self;

    /**
     * Get webhook URL
     *
     * @return string|null
     */
    public function getWebhookUrl(): ?string;

    /**
     * Set webhook URL
     *
     * @param string|null $webhookUrl
     * @return $this
     */
    public function setWebhookUrl(?string $webhookUrl): self;

    /**
     * The fulfillment tree exactly as the agent submitted it, as JSON.
     *
     * @return string|null
     */
    public function getSubmittedFulfillment(): ?string;

    /**
     * @param string|null $submittedFulfillment
     * @return $this
     */
    public function setSubmittedFulfillment(?string $submittedFulfillment): self;

    /**
     * The data-processing consent the agent recorded for this buyer, as JSON.
     *
     * @return string|null
     */
    public function getBuyerConsent(): ?string;

    /**
     * @param string|null $buyerConsent
     * @return $this
     */
    public function setBuyerConsent(?string $buyerConsent): self;

    /**
     * Get created at
     *
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * Set created at
     *
     * @param string $createdAt
     * @return $this
     */
    public function setCreatedAt(string $createdAt): self;

    /**
     * Get updated at
     *
     * @return string|null
     */
    public function getUpdatedAt(): ?string;

    /**
     * Set updated at
     *
     * @param string $updatedAt
     * @return $this
     */
    public function setUpdatedAt(string $updatedAt): self;
}
