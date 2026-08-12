<?php

/**
 * @author Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license https://magebit.com/code-license
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Config
{
    private const XML_PATH_API_BASE_URL = 'universal_commerce/api/base_url';
    private const XML_PATH_IDEMPOTENCY_TTL_HOURS = 'universal_commerce/idempotency/ttl_hours';
    private const XML_PATH_PAYMENT_METHOD = 'universal_commerce/checkout/payment_method';
    private const XML_PATH_LINKS = 'universal_commerce/links';
    private const XML_PATH_REQUIRE_REQUEST_ID = 'universal_commerce/api/require_request_id';
    private const XML_PATH_WEBHOOKS_ENABLED = 'universal_commerce/webhooks/enabled';

    /**
     * Quote lifetime in days, which is what a checkout session's expiry is derived from.
     */
    private const XML_PATH_QUOTE_LIFETIME = 'checkout/cart/delete_quote_after';

    /**
     * Link types the spec names as well-known, in the order they are published.
     */
    private const LINK_TYPES = [
        'privacy_policy',
        'terms_of_service',
        'refund_policy',
        'shipping_policy',
        'faq',
    ];

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * Get API base URL from configuration
     *
     * @param int|null $storeId
     * @return string
     */
    /**
     * @param int|null $storeId
     * @return bool
     */
    public function areWebhooksEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_WEBHOOKS_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getApiBaseUrl(?int $storeId = null): string
    {
        /** @var string|null $baseUrl */
        $baseUrl = $this->scopeConfig->getValue(
            self::XML_PATH_API_BASE_URL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($baseUrl) {
            return (string) rtrim($baseUrl, '/');
        }

        return rtrim($this->storeManager->getStore($storeId)->getBaseUrl(), '/');
    }

    /**
     * How long stored idempotent responses are retained; 0 disables cleanup.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getIdempotencyTtlHours(?int $storeId = null): int
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_IDEMPOTENCY_TTL_HOURS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Magento payment method applied when an agent completes a checkout.
     *
     * UCP payment handlers are not mapped to Magento methods yet, but an order
     * cannot be placed without one.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getPaymentMethod(?int $storeId = null): string
    {
        $method = $this->scopeConfig->getValue(
            self::XML_PATH_PAYMENT_METHOD,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return is_string($method) && $method !== '' ? $method : 'checkmo';
    }

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function isRequestIdRequired(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_REQUIRE_REQUEST_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Configured policy URLs, keyed by the spec's link type. Unset ones are omitted rather than
     * published as empty links.
     *
     * @param int|null $storeId
     * @return array<string, string>
     */
    public function getPolicyLinks(?int $storeId = null): array
    {
        $links = [];

        foreach (self::LINK_TYPES as $type) {
            $url = $this->scopeConfig->getValue(
                self::XML_PATH_LINKS . '/' . $type,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );

            if (is_string($url) && trim($url) !== '') {
                $links[$type] = trim($url);
            }
        }

        return $links;
    }

    /**
     * @param int|null $storeId
     * @return int Days a quote survives, or 0 when quotes do not expire
     */
    public function getQuoteLifetimeDays(?int $storeId = null): int
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_QUOTE_LIFETIME,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return is_numeric($value) ? (int)$value : 0;
    }
}
