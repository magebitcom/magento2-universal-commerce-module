<?php

/**
 * @author Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license https://magebit.com/code-license
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\CheckoutMeta;

use Magebit\UniversalCommerce\Api\Data\CheckoutMetaInterface;
use Magento\Framework\Model\AbstractModel;

/**
 * Checkout Meta Model
 */
class Model extends AbstractModel implements CheckoutMetaInterface
{
    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(ResourceModel::class);
    }

    /**
     * @inheritDoc
     */
    public function getEntityId(): ?int
    {
        $value = $this->getData(self::ENTITY_ID);
        return $value !== null && $value !== '' ? (int) $value : null;
    }

    /**
     * @inheritDoc
     */
    public function getCheckoutId(): ?string
    {
        return $this->getData(self::CHECKOUT_ID);
    }

    /**
     * @inheritDoc
     */
    public function setCheckoutId(string $checkoutId): CheckoutMetaInterface
    {
        return $this->setData(self::CHECKOUT_ID, $checkoutId);
    }

    /**
     * @inheritDoc
     */
    public function getQuoteId(): ?int
    {
        $value = $this->getData(self::QUOTE_ID);
        return $value !== null && $value !== '' ? (int) $value : null;
    }

    /**
     * @inheritDoc
     */
    public function setQuoteId(int $quoteId): CheckoutMetaInterface
    {
        return $this->setData(self::QUOTE_ID, $quoteId);
    }

    /**
     * @inheritDoc
     */
    public function getOrderId(): ?int
    {
        $value = $this->getData(self::ORDER_ID);
        return $value !== null && $value !== '' ? (int) $value : null;
    }

    /**
     * @inheritDoc
     */
    public function setOrderId(?int $orderId): CheckoutMetaInterface
    {
        return $this->setData(self::ORDER_ID, $orderId);
    }

    /**
     * @inheritDoc
     */
    public function getWebhookUrl(): ?string
    {
        return $this->getData(self::WEBHOOK_URL);
    }

    /**
     * @inheritDoc
     */
    public function setWebhookUrl(?string $webhookUrl): CheckoutMetaInterface
    {
        return $this->setData(self::WEBHOOK_URL, $webhookUrl);
    }

    /**
     * @inheritDoc
     */
    public function getSubmittedFulfillment(): ?string
    {
        $value = $this->getData(self::SUBMITTED_FULFILLMENT);

        return is_string($value) ? $value : null;
    }

    /**
     * @inheritDoc
     */
    public function setSubmittedFulfillment(?string $submittedFulfillment): CheckoutMetaInterface
    {
        return $this->setData(self::SUBMITTED_FULFILLMENT, $submittedFulfillment);
    }

    /**
     * @inheritDoc
     */
    public function getBuyerConsent(): ?string
    {
        $value = $this->getData(self::BUYER_CONSENT);

        return is_string($value) ? $value : null;
    }

    /**
     * @inheritDoc
     */
    public function setBuyerConsent(?string $buyerConsent): CheckoutMetaInterface
    {
        return $this->setData(self::BUYER_CONSENT, $buyerConsent);
    }

    /**
     * @inheritDoc
     */
    public function getCreatedAt(): ?string
    {
        return $this->getData(self::CREATED_AT);
    }

    /**
     * @inheritDoc
     */
    public function setCreatedAt(string $createdAt): CheckoutMetaInterface
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

    /**
     * @inheritDoc
     */
    public function getUpdatedAt(): ?string
    {
        return $this->getData(self::UPDATED_AT);
    }

    /**
     * @inheritDoc
     */
    public function setUpdatedAt(string $updatedAt): CheckoutMetaInterface
    {
        return $this->setData(self::UPDATED_AT, $updatedAt);
    }
}
