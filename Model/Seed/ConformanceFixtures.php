<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\Seed;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\ResourceModel\Rule as RuleResource;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\RuleFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The fixed catalogue and coupon set the UCP conformance run expects. Re-running updates in place, so
 * the command is safe to repeat.
 */
class ConformanceFixtures
{
    public const SKU_SIMPLE = 'ucp-conformance-simple';
    public const SKU_SECOND = 'ucp-conformance-second';
    public const SKU_OUT_OF_STOCK = 'ucp-conformance-oos';

    public const COUPON_PERCENT_10 = '10OFF';
    public const COUPON_PERCENT_20 = 'WELCOME20';
    public const COUPON_FIXED_500 = 'FIXED500';

    /**
     * Magento's out-of-the-box "Default" attribute set.
     */
    private const DEFAULT_ATTRIBUTE_SET_ID = 4;

    /**
     * Fixed prices, so the conformance run can assert exact totals.
     *
     * @var array<int, array{sku: string, name: string, price: float, in_stock: bool, qty: int}>
     */
    private const PRODUCTS = [
        [
            'sku' => self::SKU_SIMPLE,
            'name' => 'UCP Conformance Simple Product',
            'price' => 20.00,
            'in_stock' => true,
            'qty' => 1000,
        ],
        [
            'sku' => self::SKU_SECOND,
            'name' => 'UCP Conformance Second Product',
            'price' => 35.50,
            'in_stock' => true,
            'qty' => 1000,
        ],
        [
            'sku' => self::SKU_OUT_OF_STOCK,
            'name' => 'UCP Conformance Out Of Stock Product',
            'price' => 15.00,
            'in_stock' => false,
            'qty' => 0,
        ],
    ];

    /**
     * `FIXED500` is 500 minor units, which Magento expresses as a major-unit amount.
     *
     * @var array<int, array{code: string, name: string, action: string, amount: float}>
     */
    private const RULES = [
        [
            'code' => self::COUPON_PERCENT_10,
            'name' => 'UCP Conformance 10% Off',
            'action' => Rule::BY_PERCENT_ACTION,
            'amount' => 10.0,
        ],
        [
            'code' => self::COUPON_PERCENT_20,
            'name' => 'UCP Conformance 20% Off',
            'action' => Rule::BY_PERCENT_ACTION,
            'amount' => 20.0,
        ],
        [
            'code' => self::COUPON_FIXED_500,
            'name' => 'UCP Conformance 500 Minor Units Off',
            'action' => Rule::CART_FIXED_ACTION,
            'amount' => 5.00,
        ],
    ];

    /**
     * @param ProductRepositoryInterface $productRepository
     * @param ProductFactory $productFactory
     * @param RuleFactory $ruleFactory
     * @param RuleResource $ruleResource
     * @param CouponFactory $couponFactory
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductFactory $productFactory,
        private readonly RuleFactory $ruleFactory,
        private readonly RuleResource $ruleResource,
        private readonly CouponFactory $couponFactory,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * @return array<int, array{sku: string, name: string, price: float, in_stock: bool, qty: int}>
     */
    public static function products(): array
    {
        return self::PRODUCTS;
    }

    /**
     * @return array<int, array{code: string, name: string, action: string, amount: float}>
     */
    public static function rules(): array
    {
        return self::RULES;
    }

    /**
     * @return string[] SKUs that were created or updated
     */
    public function applyProducts(): array
    {
        $applied = [];

        foreach (self::PRODUCTS as $definition) {
            $this->applyProduct($definition);
            $applied[] = $definition['sku'];
        }

        return $applied;
    }

    /**
     * @return string[] Coupon codes that were created or updated
     */
    public function applyRules(): array
    {
        $applied = [];

        foreach (self::RULES as $definition) {
            $this->applyRule($definition);
            $applied[] = $definition['code'];
        }

        return $applied;
    }

    /**
     * @param array{sku: string, name: string, price: float, in_stock: bool, qty: int} $definition
     * @return void
     */
    private function applyProduct(array $definition): void
    {
        try {
            /** @var Product $product */
            $product = $this->productRepository->get($definition['sku'], true, null, true);
        } catch (NoSuchEntityException $exception) {
            $product = $this->productFactory->create();
            $product->setSku($definition['sku']);
            $product->setTypeId(Type::TYPE_SIMPLE);
            $product->setAttributeSetId(self::DEFAULT_ATTRIBUTE_SET_ID);
        }

        $product->setName($definition['name']);
        $product->setPrice($definition['price']);
        $product->setStatus(Status::STATUS_ENABLED);
        $product->setVisibility(Visibility::VISIBILITY_BOTH);
        $product->setWebsiteIds(array_keys($this->storeManager->getWebsites()));
        $product->setStockData([
            'use_config_manage_stock' => 1,
            'is_in_stock' => $definition['in_stock'] ? 1 : 0,
            'qty' => $definition['qty'],
        ]);

        $this->productRepository->save($product);
    }

    /**
     * @param array{code: string, name: string, action: string, amount: float} $definition
     * @return void
     */
    private function applyRule(array $definition): void
    {
        $rule = $this->findRuleByCoupon($definition['code']) ?? $this->ruleFactory->create();

        $rule->setName($definition['name']);
        $rule->setDescription('Created by magebit:seed:ucp-conformance.');
        $rule->setIsActive(1);
        $rule->setCouponType(Rule::COUPON_TYPE_SPECIFIC);
        $rule->setCouponCode($definition['code']);
        $rule->setSimpleAction($definition['action']);
        $rule->setDiscountAmount($definition['amount']);
        $rule->setDiscountQty(0);
        $rule->setStopRulesProcessing(false);
        $rule->setSortOrder(0);
        $rule->setUsesPerCustomer(0);
        $rule->setCustomerGroupIds($this->getAllCustomerGroupIds());
        $rule->setWebsiteIds(array_keys($this->storeManager->getWebsites()));

        $this->ruleResource->save($rule);
    }

    /**
     * @param string $code
     * @return Rule|null
     */
    private function findRuleByCoupon(string $code): ?Rule
    {
        $coupon = $this->couponFactory->create()->loadByCode($code);
        $ruleId = $coupon->getRuleId();

        if (!$ruleId) {
            return null;
        }

        $rule = $this->ruleFactory->create();
        $this->ruleResource->load($rule, (int)$ruleId);

        return $rule->getId() ? $rule : null;
    }

    /**
     * Every group, so the conformance run's guest carts qualify.
     *
     * @return int[]
     */
    private function getAllCustomerGroupIds(): array
    {
        return [0, 1, 2, 3];
    }
}
