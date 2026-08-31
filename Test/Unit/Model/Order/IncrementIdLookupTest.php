<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit\Model\Order;

use Magebit\UniversalCommerce\Model\Order\IncrementIdLookup;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

class IncrementIdLookupTest extends TestCase
{
    private const ORDER_ID = '000000123';

    /**
     * @return void
     */
    public function testAnExistingOrderIsReturned(): void
    {
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->getMock();

        $this->assertSame($order, $this->lookup([$order])->find(self::ORDER_ID));
    }

    /**
     * @return void
     */
    public function testAnUnknownOrderIsNull(): void
    {
        $this->assertNull($this->lookup([])->find(self::ORDER_ID));
    }

    /**
     * A missing identifier must not turn into an unfiltered search that serves an arbitrary order.
     *
     * @return void
     */
    public function testAnEmptyIdentifierNeverReachesTheRepository(): void
    {
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->expects($this->never())->method('getList');

        $lookup = new IncrementIdLookup($repository, $this->searchCriteriaBuilder());

        $this->assertNull($lookup->find(''));
    }

    /**
     * The repository can hand back rows that are not orders, and those must not be returned as one.
     *
     * @return void
     */
    public function testANonOrderRowIsIgnored(): void
    {
        $this->assertNull($this->lookup([new \stdClass()])->find(self::ORDER_ID));
    }

    /**
     * @param array<mixed> $items Rows the repository returns
     * @return IncrementIdLookup
     */
    private function lookup(array $items): IncrementIdLookup
    {
        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn($items);

        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->method('getList')->willReturn($searchResult);

        return new IncrementIdLookup($repository, $this->searchCriteriaBuilder());
    }

    /**
     * @return SearchCriteriaBuilder
     */
    private function searchCriteriaBuilder(): SearchCriteriaBuilder
    {
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnSelf();
        $builder->method('setPageSize')->willReturnSelf();
        $builder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        return $builder;
    }
}
