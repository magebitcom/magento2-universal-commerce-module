<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit\Model\Service\Shopping;

use Magebit\UcpSpec\Api\Shopping\CatalogLookupDetailProductInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\CatalogLookupGetProductResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\CatalogLookupLookupResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\CatalogSearchSearchResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\InputCorrelationInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\PaginationResponseInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\ProductInterface;
use Magebit\UcpSpec\Api\UcpResponseCatalogSchemaInterfaceFactory;
use Magebit\UcpSpec\Data\Shopping\CatalogLookupDetailProduct;
use Magebit\UcpSpec\Data\Shopping\CatalogLookupGetProductResponse;
use Magebit\UcpSpec\Data\Shopping\CatalogLookupLookupResponse;
use Magebit\UcpSpec\Data\Shopping\CatalogSearchSearchResponse;
use Magebit\UcpSpec\Data\Shopping\Types\InputCorrelation;
use Magebit\UcpSpec\Data\Shopping\Types\PaginationResponse;
use Magebit\UcpSpec\Data\Shopping\Types\Product;
use Magebit\UcpSpec\Data\Shopping\Types\Variant;
use Magebit\UcpSpec\Data\UcpResponseCatalogSchema;
use Magebit\UniversalCommerce\Exception\UcpException;
use Magebit\UniversalCommerce\Model\Discovery\ServiceRegistry;
use Magebit\UniversalCommerce\Model\Service\Shopping\CatalogHandler;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\ProductToUcpProduct;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product as MagentoProduct;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class CatalogHandlerTest extends TestCase
{
    private const STORE_ID = 3;
    private const VISIBLE_IDS = [2, 3, 4];

    /**
     * Every filter the handler put on a collection, as [attribute, condition].
     *
     * @var array<int, array{0: mixed, 1: mixed}>
     */
    private array $filters = [];

    /**
     * Every page the handler asked the database for, as [size, offset].
     *
     * @var array<int, array{0: mixed, 1: mixed}>
     */
    private array $pages = [];

    /**
     * @var array<int, array<int, int>>
     */
    private array $visibilities = [];

    /**
     * @var int[]
     */
    private array $storeFilters = [];

    /**
     * Every list of ids the handler narrowed a collection down to.
     *
     * @var array<int, mixed>
     */
    private array $idFilters = [];

    private bool $distinctAsked = false;

    /**
     * The rows the fake collection hands back.
     *
     * @var MagentoProduct[]
     */
    private array $items = [];

    private int $collections = 0;

    private int $total = 0;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->filters = [];
        $this->pages = [];
        $this->visibilities = [];
        $this->storeFilters = [];
        $this->items = [];
        $this->idFilters = [];
        $this->distinctAsked = false;
        $this->collections = 0;
        $this->total = 0;
    }

    /**
     * The endpoint is public, so a huge list of ids must not turn into a huge number of product reads.
     *
     * @return void
     */
    public function testLookupAsksForNoMoreIdsThanTheMaximum(): void
    {
        $ids = [];

        for ($i = 0; $i < 500; $i++) {
            $ids[] = 'ucp-' . $i;
        }

        $this->handler()->lookup($ids);

        $this->assertCount(CatalogHandler::MAX_LIMIT, $this->skusAsked());
    }

    /**
     * The whole batch is one query, not one query per id.
     *
     * @return void
     */
    public function testLookupReadsTheWholeBatchWithOneQuery(): void
    {
        $this->handler()->lookup(['ucp-one', 'ucp-two', 'ucp-three']);

        $this->assertSame(1, $this->collections);
        $this->assertSame(['ucp-one', 'ucp-two', 'ucp-three'], $this->skusAsked());
    }

    /**
     * @return void
     */
    public function testAnIdThatMatchesNothingIsLeftOutOfTheResponse(): void
    {
        $this->items = [$this->product('ucp-one')];

        $products = $this->handler()->lookup(['ucp-one', 'ucp-gone'])->getProducts();

        $this->assertCount(1, $products);
        $this->assertSame('ucp-one', $products[0]->getId());
    }

    /**
     * @return void
     */
    public function testLookupReadsOnlyProductsTheStorefrontShows(): void
    {
        $this->handler()->lookup(['ucp-one']);

        $this->assertContains(['status', ['eq' => Status::STATUS_ENABLED]], $this->filters);
        $this->assertSame([self::VISIBLE_IDS], $this->visibilities);
        $this->assertSame([self::STORE_ID], $this->storeFilters);
    }

    /**
     * A SKU the storefront hides must read as missing, the same way search never offers it.
     *
     * @return void
     */
    public function testAHiddenSkuIsReportedAsNotFound(): void
    {
        $this->expectException(UcpException::class);
        $this->expectExceptionMessage('Product not found: ucp-hidden.');

        $this->handler()->getProduct('ucp-hidden');
    }

    /**
     * @return void
     */
    public function testSearchReadsOnlyProductsTheStorefrontShows(): void
    {
        $this->handler()->search('shoes');

        $this->assertContains(['status', ['eq' => Status::STATUS_ENABLED]], $this->filters);
        $this->assertSame([self::VISIBLE_IDS], $this->visibilities);
        $this->assertSame([self::STORE_ID], $this->storeFilters);
    }

    /**
     * An empty query is allowed and matches the whole catalogue, so no text filter is added at all.
     *
     * @return void
     */
    public function testAnEmptySearchQueryAddsNoTextFilter(): void
    {
        $this->handler()->search(null);
        $this->handler()->search('   ');

        $this->assertFalse($this->hasTextFilter());
    }

    /**
     * @return void
     */
    public function testASearchQueryFiltersOnNameAndSku(): void
    {
        $this->handler()->search('shoes');

        $this->assertTrue($this->hasTextFilter());
    }

    /**
     * The page is cut by the database. The cursor offset is any number, not a multiple of the size.
     *
     * @return void
     */
    public function testSearchAsksTheDatabaseForOnePage(): void
    {
        $this->handler()->search(null, 5, 'offset:7');

        $this->assertSame([[5, 7]], $this->pages);
    }

    /**
     * @return void
     */
    public function testSearchNeverAsksForMoreThanTheMaximumPageSize(): void
    {
        $this->handler()->search(null, 5000);

        $this->assertSame([[CatalogHandler::MAX_LIMIT, 0]], $this->pages);
    }

    /**
     * @return void
     */
    public function testTheTotalIsTheCountedNumberOfProducts(): void
    {
        $this->total = 137;

        $pagination = $this->handler()->search(null, 20)->getPagination();

        $this->assertSame(137, $pagination->getTotalCount());
        $this->assertTrue($pagination->getHasNextPage());
        $this->assertSame('offset:20', $pagination->getCursor());
    }

    /**
     * The text filter joins an attribute table, which can list one product on several rows. The page
     * is read as distinct ids and the products fetched by those, so a page holds the number of
     * products asked for rather than however many rows the join produced.
     *
     * @return void
     */
    public function testThePageIsReadAsDistinctProductIds(): void
    {
        $this->total = 2;
        $this->items = [$this->product('ucp-one'), $this->product('ucp-two')];

        $this->handler()->search('shoes', 20);

        $this->assertTrue($this->distinctAsked);
        $this->assertSame([[1, 2]], $this->idFilters);
    }

    /**
     * @return void
     */
    public function testTheLastPageHasNoCursor(): void
    {
        $this->total = 2;
        $this->items = [$this->product('ucp-one'), $this->product('ucp-two')];

        $pagination = $this->handler()->search(null, 20)->getPagination();

        $this->assertFalse($pagination->getHasNextPage());
        $this->assertNull($pagination->getCursor());
    }

    /**
     * @return string[]
     */
    private function skusAsked(): array
    {
        foreach ($this->filters as [$attribute, $condition]) {
            if ($attribute === 'sku' && is_array($condition) && isset($condition['in'])) {
                return (array) $condition['in'];
            }
        }

        return [];
    }

    /**
     * The name-or-sku filter is the only one passed as a list of conditions.
     *
     * @return bool
     */
    private function hasTextFilter(): bool
    {
        foreach ($this->filters as [$attribute]) {
            if (is_array($attribute)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $sku
     * @return MagentoProduct
     */
    private function product(string $sku): MagentoProduct
    {
        $product = $this->getMockBuilder(MagentoProduct::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSku', 'getTypeId', 'getProductUrl'])
            ->getMock();
        $product->method('getSku')->willReturn($sku);
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getProductUrl')->willReturn('https://example.com/' . $sku);

        return $product;
    }

    /**
     * @return CatalogHandler
     */
    private function handler(): CatalogHandler
    {
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturnCallback(
            fn (): ProductCollection => $this->collection()
        );

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(self::STORE_ID);
        $store->method('getCurrentCurrencyCode')->willReturn('EUR');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $visibility = $this->createMock(Visibility::class);
        $visibility->method('getVisibleInSiteIds')->willReturn(self::VISIBLE_IDS);

        $imageHelper = $this->createMock(ImageHelper::class);
        $imageHelper->method('init')->willReturnSelf();
        $imageHelper->method('getUrl')->willReturn('https://example.com/image.jpg');

        return new CatalogHandler(
            $collectionFactory,
            $this->converter(),
            $this->factoryFor(CatalogSearchSearchResponseInterfaceFactory::class, CatalogSearchSearchResponse::class),
            $this->factoryFor(CatalogLookupLookupResponseInterfaceFactory::class, CatalogLookupLookupResponse::class),
            $this->factoryFor(
                CatalogLookupGetProductResponseInterfaceFactory::class,
                CatalogLookupGetProductResponse::class
            ),
            $this->factoryFor(CatalogLookupDetailProductInterfaceFactory::class, CatalogLookupDetailProduct::class),
            $this->factoryFor(PaginationResponseInterfaceFactory::class, PaginationResponse::class),
            $this->factoryFor(UcpResponseCatalogSchemaInterfaceFactory::class, UcpResponseCatalogSchema::class),
            $this->createMock(ServiceRegistry::class),
            $storeManager,
            $imageHelper,
            $visibility,
            $this->factoryFor(InputCorrelationInterfaceFactory::class, InputCorrelation::class)
        );
    }

    /**
     * @return ProductToUcpProduct
     */
    private function converter(): ProductToUcpProduct
    {
        $converter = $this->createMock(ProductToUcpProduct::class);
        $converter->method('convert')->willReturnCallback(
            static function (MagentoProduct $product): ProductInterface {
                $sku = (string) $product->getSku();

                return new Product([
                    'id' => $sku,
                    'variants' => [new Variant(['id' => $sku, 'sku' => $sku])],
                ]);
            }
        );

        return $converter;
    }

    /**
     * A stand-in for the product collection: it records what the handler filtered, paged and ordered by,
     * and hands back the rows the test set up.
     *
     * @return ProductCollection
     */
    private function collection(): ProductCollection
    {
        $this->collections++;

        $collection = $this->getMockBuilder(ProductCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'addAttributeToSelect',
                'addAttributeToFilter',
                'setVisibility',
                'addStoreFilter',
                'setFlag',
                'getSelect',
                'getConnection',
                'getAllIds',
                'getIterator',
                'addIdFilter',
            ])
            ->getMock();

        // Reading every id and paging in PHP is the thing being fixed, so it must not come back.
        $collection->expects($this->never())->method('getAllIds');

        $collection->method('getSelect')->willReturn($this->select());
        $collection->method('getConnection')->willReturn($this->connection());
        $collection->method('addIdFilter')->willReturnCallback(
            function (mixed $ids, bool $exclude = false) use ($collection): ProductCollection {
                $this->idFilters[] = $ids;

                return $collection;
            }
        );
        $collection->method('getIterator')->willReturnCallback(
            fn (): \ArrayIterator => new \ArrayIterator($this->items)
        );
        $collection->method('addAttributeToFilter')->willReturnCallback(
            function (mixed $attribute, mixed $condition = null) use ($collection): ProductCollection {
                $this->filters[] = [$attribute, $condition];

                return $collection;
            }
        );
        $collection->method('setVisibility')->willReturnCallback(
            function (array $visibility) use ($collection): ProductCollection {
                $this->visibilities[] = $visibility;

                return $collection;
            }
        );
        $collection->method('addStoreFilter')->willReturnCallback(
            function (mixed $store = null) use ($collection): ProductCollection {
                $this->storeFilters[] = (int) $store;

                return $collection;
            }
        );

        return $collection;
    }

    /**
     * @return Select
     */
    private function select(): Select
    {
        $select = $this->createMock(Select::class);
        $select->method('reset')->willReturnSelf();
        $select->method('columns')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('distinct')->willReturnCallback(
            function (bool $flag = true) use ($select): Select {
                $this->distinctAsked = $this->distinctAsked || $flag;

                return $select;
            }
        );
        $select->method('limit')->willReturnCallback(
            function (mixed $count = null, mixed $offset = null) use ($select): Select {
                $this->pages[] = [$count, $offset];

                return $select;
            }
        );

        return $select;
    }

    /**
     * @return string[] One id per row the fake collection holds
     */
    private function pageIdRows(): array
    {
        $ids = [];

        for ($position = 1; $position <= count($this->items); $position++) {
            $ids[] = (string) $position;
        }

        return $ids;
    }

    /**
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchOne')->willReturnCallback(fn (): string => (string) $this->total);
        // The page is read as a list of ids, one for every row the fake collection holds.
        $connection->method('fetchCol')->willReturnCallback(fn (): array => $this->pageIdRows());

        return $connection;
    }

    /**
     * @param class-string $factoryClass
     * @param class-string $concrete
     * @return mixed
     */
    private function factoryFor(string $factoryClass, string $concrete): mixed
    {
        $factory = $this->createMock($factoryClass);
        $factory->method('create')->willReturnCallback(
            static fn (array $args = []): object => new $concrete($args['data'] ?? [])
        );

        return $factory;
    }
}
