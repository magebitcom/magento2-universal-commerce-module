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

use Magebit\UcpSpec\Api\Shopping\CatalogLookupGetProductResponseInterface;
use Magebit\UcpSpec\Api\Shopping\CatalogLookupDetailProductInterface;
use Magebit\UcpSpec\Api\Shopping\CatalogLookupDetailProductInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\CatalogLookupGetProductResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\CatalogLookupLookupResponseInterface;
use Magebit\UcpSpec\Api\Shopping\CatalogLookupLookupResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\CatalogSearchSearchResponseInterface;
use Magebit\UcpSpec\Api\Shopping\CatalogSearchSearchResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\PaginationResponseInterface;
use Magebit\UcpSpec\Api\Shopping\Types\PaginationResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\InputCorrelationInterface;
use Magebit\UcpSpec\Api\Shopping\Types\InputCorrelationInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\ProductInterface;
use Magebit\UcpSpec\Api\UcpResponseCatalogSchemaInterface;
use Magebit\UcpSpec\Runtime\SpecObject;
use Magebit\UcpSpec\Api\UcpResponseCatalogSchemaInterfaceFactory;
use Magebit\UniversalCommerce\Api\Service\Shopping\CatalogHandlerInterface;
use Magebit\UniversalCommerce\Api\UniversalCommerceProtocolInterface;
use Magebit\UniversalCommerce\Exception\UcpException;
use Magebit\UniversalCommerce\Model\Discovery\ServiceRegistry;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\ProductToUcpProduct;
use Magento\Catalog\Api\Data\ProductInterface as CatalogProductInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product as MagentoProduct;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Model\ResourceModel\Stock\Status as StockStatus;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Serves catalog search and lookup. Both are read-only: nothing here touches a quote.
 */
class CatalogHandler implements CatalogHandlerInterface
{
    /**
     * A page size an agent cannot exceed, so a missing or huge limit cannot walk the whole catalogue.
     */
    public const MAX_LIMIT = 50;
    public const DEFAULT_LIMIT = 20;

    /**
     * The cursor is the zero-based offset of the next page, encoded so it stays opaque to the agent.
     */
    private const CURSOR_PREFIX = 'offset:';

    /**
     * Only reachable if the store is not a concrete Store, which the interface allows but a real request
     * never produces.
     */
    private const FALLBACK_CURRENCY = 'USD';

    /**
     * Tells Magento the stock filter is already handled, so it does not silently drop out-of-stock rows.
     */
    private const STOCK_FILTER_FLAG = 'has_stock_status_filter';

    /**
     * Lookup correlation: how a requested identifier resolved to a variant.
     */
    private const INPUTS_KEY = 'inputs';
    private const MATCH_EXACT = 'exact';
    private const MATCH_FEATURED = 'featured';

    /**
     * @param CollectionFactory $collectionFactory
     * @param ProductToUcpProduct $productConverter
     * @param CatalogSearchSearchResponseInterfaceFactory $searchResponseFactory
     * @param CatalogLookupLookupResponseInterfaceFactory $lookupResponseFactory
     * @param CatalogLookupGetProductResponseInterfaceFactory $productResponseFactory
     * @param CatalogLookupDetailProductInterfaceFactory $detailProductFactory
     * @param PaginationResponseInterfaceFactory $paginationFactory
     * @param UcpResponseCatalogSchemaInterfaceFactory $ucpCatalogFactory
     * @param ServiceRegistry $serviceRegistry
     * @param StoreManagerInterface $storeManager
     * @param ImageHelper $imageHelper
     * @param Visibility $visibility
     * @param InputCorrelationInterfaceFactory $correlationFactory
     * @param ConfigurableResource $configurableResource
     * @param StockStatus $stockStatus
     * @param MetadataPool $metadataPool
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly ProductToUcpProduct $productConverter,
        private readonly CatalogSearchSearchResponseInterfaceFactory $searchResponseFactory,
        private readonly CatalogLookupLookupResponseInterfaceFactory $lookupResponseFactory,
        private readonly CatalogLookupGetProductResponseInterfaceFactory $productResponseFactory,
        private readonly CatalogLookupDetailProductInterfaceFactory $detailProductFactory,
        private readonly PaginationResponseInterfaceFactory $paginationFactory,
        private readonly UcpResponseCatalogSchemaInterfaceFactory $ucpCatalogFactory,
        private readonly ServiceRegistry $serviceRegistry,
        private readonly StoreManagerInterface $storeManager,
        private readonly ImageHelper $imageHelper,
        private readonly Visibility $visibility,
        private readonly InputCorrelationInterfaceFactory $correlationFactory,
        private readonly ConfigurableResource $configurableResource,
        private readonly StockStatus $stockStatus,
        private readonly MetadataPool $metadataPool
    ) {
    }

    /**
     * @inheritDoc
     */
    public function search(
        ?string $query,
        ?int $limit = null,
        ?string $cursor = null
    ): CatalogSearchSearchResponseInterface {
        $pageSize = $this->pageSize($limit);
        $offset = $this->offsetFrom($cursor);

        $collection = $this->visibleProducts();

        if ($query !== null && trim($query) !== '') {
            // Name and SKU rather than the fulltext index: the index needs reindexing to be current, and a
            // stale index would answer a live catalogue query with yesterday's products.
            $collection->addAttributeToFilter([
                ['attribute' => 'name', 'like' => '%' . $query . '%'],
                ['attribute' => 'sku', 'like' => '%' . $query . '%'],
            ]);
        }

        // Counted from distinct ids rather than getSize(): with the OR'd EAV filter above, the count
        // select over-reports, and an agent would page against a total that does not exist.
        $total = $this->totalOf($collection);

        // Ordered and limited in the query. Reading every id and cutting the page in PHP meant one
        // full scan of the catalogue per request, and the cursor offset can be any number.
        $ids = $this->pageIds($collection, $pageSize, $offset);

        $found = [];

        if ($ids !== []) {
            $collection->addIdFilter($ids);
            $collection->getSelect()->order('e.entity_id ' . Select::SQL_ASC);

            foreach ($collection as $product) {
                if ($product instanceof MagentoProduct) {
                    $found[] = $product;
                }
            }
        }

        $products = $this->convertAll($found);

        /** @var CatalogSearchSearchResponseInterface $response */
        $response = $this->searchResponseFactory->create();
        $response->setUcp($this->ucp());
        $response->setProducts($products);
        $response->setPagination($this->pagination($offset + $pageSize, $total));

        return $response;
    }

    /**
     * @inheritDoc
     */
    public function lookup(array $ids): CatalogLookupLookupResponseInterface
    {
        // Capped at the same size as a search page, because the endpoint is public and every extra id
        // used to mean another product load in the same request.
        $skus = array_map(
            static fn (mixed $id): string => (string) $id,
            array_slice(array_values($ids), 0, self::MAX_LIMIT)
        );

        $found = $this->findBySkus($skus);
        $matched = [];
        $requested = [];

        foreach ($skus as $sku) {
            $product = $found[strtolower($sku)] ?? null;

            // An id that matches nothing is left out rather than failing the batch: an agent asking about
            // several products should still learn about the ones that exist.
            if ($product !== null) {
                $matched[] = $product;
                $requested[] = $sku;
            }
        }

        $products = [];

        foreach ($this->convertAll($matched) as $index => $converted) {
            $products[] = $this->correlate($converted, $requested[$index]);
        }

        /** @var CatalogLookupLookupResponseInterface $response */
        $response = $this->lookupResponseFactory->create();
        $response->setUcp($this->ucp());
        $response->setProducts($products);

        return $response;
    }

    /**
     * @inheritDoc
     * @throws UcpException
     */
    public function getProduct(string $id): CatalogLookupGetProductResponseInterface
    {
        $product = $this->findBySku($id);

        if ($product === null) {
            throw new UcpException(__('Product not found: %1.', $id), 'not_found', 404);
        }

        /** @var CatalogLookupGetProductResponseInterface $response */
        $response = $this->productResponseFactory->create();
        $response->setUcp($this->ucp());
        $response->setProduct($this->asDetail($this->convertAll([$product])[0]));

        return $response;
    }

    /**
     * A lookup variant has to say which requested identifier resolved to it: `exact` when the id names
     * the variant itself, `featured` when the server chose it on the caller's behalf.
     *
     * @param ProductInterface $product
     * @param string $requestedId
     * @return ProductInterface
     */
    private function correlate(ProductInterface $product, string $requestedId): ProductInterface
    {
        foreach ($product->getVariants() as $variant) {
            if (!$variant instanceof SpecObject) {
                continue;
            }

            $match = $variant->getSku() === $requestedId ? self::MATCH_EXACT : self::MATCH_FEATURED;

            $variant->set(self::INPUTS_KEY, [$this->correlationFactory->create(['data' => [
                InputCorrelationInterface::KEY_ID => $requestedId,
                InputCorrelationInterface::KEY_MATCH => $match,
            ]])]);
        }

        return $product;
    }

    /**
     * Loads a whole batch of SKUs with one query. Keyed without case, the way the database compares
     * SKUs, so a differently cased id still finds its product.
     *
     * @param string[] $skus
     * @return array<string, MagentoProduct>
     */
    private function findBySkus(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        $collection = $this->visibleProducts();
        $collection->addAttributeToFilter('sku', ['in' => $skus]);

        $found = [];

        foreach ($collection as $product) {
            if ($product instanceof MagentoProduct) {
                $found[strtolower((string) $product->getSku())] = $product;
            }
        }

        return $found;
    }

    /**
     * The single-product response takes the richer detail type, which is the product plus option
     * selections. The extra fields need configurable option axes we do not read yet.
     *
     * @param ProductInterface $product
     * @return CatalogLookupDetailProductInterface
     */
    private function asDetail(ProductInterface $product): CatalogLookupDetailProductInterface
    {
        $data = $product instanceof SpecObject ? $product->toArray() : [];

        return $this->detailProductFactory->create(['data' => $data]);
    }

    /**
     * Converts a whole page in one go. The variants behind it and the stock behind those are read once
     * for the page; reading them per product cost a query for every parent and two for every variant.
     *
     * @param MagentoProduct[] $products
     * @return ProductInterface[]
     */
    private function convertAll(array $products): array
    {
        $children = $this->childrenFor($products);
        $currencyCode = $this->currencyCode();
        $linkField = $this->metadataPool->getMetadata(CatalogProductInterface::class)->getLinkField();

        $converted = [];

        foreach ($products as $product) {
            $converted[] = $this->productConverter->convert(
                $product,
                $currencyCode,
                $children[$this->idOf($product->getData($linkField))] ?? [],
                $this->urlOf($product),
                $this->imageOf($product)
            );
        }

        return $converted;
    }

    /**
     * Loads the variants of every configurable product on the page: one query for which variant
     * belongs to which product, and one for the variants themselves.
     *
     * @param MagentoProduct[] $products
     * @return array<int, MagentoProduct[]> Variants, keyed by the product they belong to
     */
    private function childrenFor(array $products): array
    {
        $linkField = $this->metadataPool->getMetadata(CatalogProductInterface::class)->getLinkField();
        $parentIds = [];

        foreach ($products as $product) {
            if ($product->getTypeId() === Configurable::TYPE_CODE) {
                $parentIds[] = $this->idOf($product->getData($linkField));
            }
        }

        $links = $this->variantLinks(array_filter($parentIds));

        if ($links === []) {
            return [];
        }

        $loaded = $this->loadVariants(array_unique(array_merge(...array_values($links))));
        $children = [];

        foreach ($links as $parentId => $variantIds) {
            foreach ($variantIds as $variantId) {
                // A variant the query left out, because it is out of stock or needs options chosen,
                // is not offered here either.
                if (isset($loaded[$variantId])) {
                    $children[$parentId][] = $loaded[$variantId];
                }
            }
        }

        return $children;
    }

    /**
     * @param mixed $value
     * @return int A product id, or zero when there is none to read
     */
    private function idOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Which variant belongs to which product. Read from the link table rather than through Magento's
     * variant collection, because that collection cannot hold a variant shared by two products.
     *
     * @param int[] $parentIds
     * @return array<int, int[]> Variant ids, keyed by the product they belong to
     */
    private function variantLinks(array $parentIds): array
    {
        if ($parentIds === []) {
            return [];
        }

        $connection = $this->configurableResource->getConnection();

        if (!$connection instanceof AdapterInterface) {
            return [];
        }

        $select = $connection->select()
            ->from($this->configurableResource->getMainTable(), ['parent_id', 'product_id'])
            ->where('parent_id IN (?)', $parentIds);

        $links = [];

        foreach ($connection->fetchAll($select) as $row) {
            $links[$this->idOf($row['parent_id'])][] = $this->idOf($row['product_id']);
        }

        return $links;
    }

    /**
     * @param int[] $variantIds
     * @return array<int, MagentoProduct> Variants, keyed by their own id
     */
    private function loadVariants(array $variantIds): array
    {
        $storeId = (int) $this->storeManager->getStore()->getId();

        $collection = $this->collectionFactory->create();
        $collection->addIdFilter($variantIds);
        // Description is left out on purpose: a variant would repeat its parent's whole description,
        // which says nothing new and makes the answer much bigger.
        $collection->addAttributeToSelect(['name', 'price', 'special_price', 'status', 'visibility']);
        $collection->addFilterByRequiredOptions();
        $collection->addStoreFilter($storeId);
        $collection->setStoreId($storeId);

        $variants = [];

        foreach ($collection->getItems() as $variant) {
            if ($variant instanceof MagentoProduct) {
                $variants[(int) $variant->getId()] = $variant;
            }
        }

        return $variants;
    }

    /**
     * Read through the same visible-products query as search, so a product the storefront hides is not
     * something an agent can pull out by knowing its SKU.
     *
     * @param string $sku
     * @return MagentoProduct|null
     */
    private function findBySku(string $sku): ?MagentoProduct
    {
        return $this->findBySkus([$sku])[strtolower($sku)] ?? null;
    }

    /**
     * Reads the ids for one page on a query of its own. The name-or-sku filter joins an attribute
     * table, which can list one product on several rows, so limiting the main query would cut the
     * page out of rows rather than products and hand back a short page.
     *
     * @param ProductCollection $collection Collection carrying the search filters
     * @param int $pageSize How many products the page holds
     * @param int $offset How many products to skip
     * @return int[]
     */
    private function pageIds(ProductCollection $collection, int $pageSize, int $offset): array
    {
        $select = $this->bareSelect($collection);
        $select->distinct(true);
        $select->columns('e.entity_id');
        $select->order('e.entity_id ' . Select::SQL_ASC);
        $select->limit($pageSize, $offset);

        return array_map('intval', $collection->getConnection()->fetchCol($select));
    }

    /**
     * Counts distinct ids on the collection's own query. The name-or-sku filter joins an attribute
     * table, which can list one product on several rows.
     *
     * @param ProductCollection $collection
     * @return int
     */
    private function totalOf(ProductCollection $collection): int
    {
        $select = $this->bareSelect($collection);
        $select->columns(new Expression('COUNT(DISTINCT e.entity_id)'));

        return (int) $collection->getConnection()->fetchOne($select);
    }

    /**
     * The collection's query with everything but its filters stripped, so a caller can ask its own
     * question of the same rows.
     *
     * @param ProductCollection $collection
     * @return Select
     */
    private function bareSelect(ProductCollection $collection): Select
    {
        $select = clone $collection->getSelect();
        $select->reset(Select::COLUMNS);
        $select->reset(Select::ORDER);
        $select->reset(Select::GROUP);
        $select->reset(Select::LIMIT_COUNT);
        $select->reset(Select::LIMIT_OFFSET);

        return $select;
    }

    /**
     * @return ProductCollection
     */
    private function visibleProducts(): ProductCollection
    {
        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect(['name', 'description', 'price', 'image', 'status', 'visibility']);
        $collection->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED]);
        $collection->setVisibility($this->visibility->getVisibleInSiteIds());
        $collection->addStoreFilter((int) $this->storeManager->getStore()->getId());

        // Reads the whole page's storefront addresses at once. Asking each product for its own url
        // meant another query per product.
        $collection->addUrlRewrite();

        // Out-of-stock products are kept and reported with `availability.available = false`. Magento
        // would otherwise drop them at load but not from a count, so the two disagreed; the spec models
        // out-of-stock explicitly, and an agent is better told a product exists than left guessing.
        $collection->setFlag(self::STOCK_FILTER_FLAG, true);

        // Reads every product's stock as part of this same query. The flag above stops Magento adding
        // the join a second time, and `false` keeps out-of-stock rows rather than filtering them.
        $this->stockStatus->addStockDataToCollection($collection, false);

        return $collection;
    }

    /**
     * @param int|null $limit
     * @return int
     */
    private function pageSize(?int $limit): int
    {
        if ($limit === null || $limit < 1) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }

    /**
     * @param string|null $cursor
     * @return int
     */
    private function offsetFrom(?string $cursor): int
    {
        if ($cursor === null || !str_starts_with($cursor, self::CURSOR_PREFIX)) {
            return 0;
        }

        $offset = (int) substr($cursor, strlen(self::CURSOR_PREFIX));

        return max(0, $offset);
    }

    /**
     * @param int $nextOffset
     * @param int $total
     * @return PaginationResponseInterface
     */
    private function pagination(int $nextOffset, int $total): PaginationResponseInterface
    {
        $hasNext = $nextOffset < $total;

        /** @var PaginationResponseInterface $pagination */
        $pagination = $this->paginationFactory->create();
        $pagination->setHasNextPage($hasNext);
        $pagination->setTotalCount($total);

        if ($hasNext) {
            $pagination->setCursor(self::CURSOR_PREFIX . $nextOffset);
        }

        return $pagination;
    }

    /**
     * @return UcpResponseCatalogSchemaInterface
     */
    private function ucp(): UcpResponseCatalogSchemaInterface
    {
        $service = $this->serviceRegistry->getService('dev.ucp.shopping');

        return $this->ucpCatalogFactory->create([
            'data' => [
                UcpResponseCatalogSchemaInterface::KEY_VERSION => UniversalCommerceProtocolInterface::SPEC_VERSION,
                UcpResponseCatalogSchemaInterface::KEY_CAPABILITIES => $service ? $service->getCapabilities() : [],
            ]
        ]);
    }

    /**
     * @return string
     */
    private function currencyCode(): string
    {
        $store = $this->storeManager->getStore();

        return $store instanceof Store ? (string) $store->getCurrentCurrencyCode() : self::FALLBACK_CURRENCY;
    }

    /**
     * @param MagentoProduct $product
     * @return string
     */
    private function urlOf(MagentoProduct $product): string
    {
        return (string) $product->getProductUrl();
    }

    /**
     * @param MagentoProduct $product
     * @return string
     */
    private function imageOf(MagentoProduct $product): string
    {
        return (string) $this->imageHelper->init($product, 'product_page_image_large')->getUrl();
    }
}
