<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit\Model\Service\Shopping\Converter;

use Magebit\AgenticCore\Model\Money\MinorUnits;
use Magebit\UcpSpec\Api\Shopping\Types\DescriptionInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\MediaInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\PriceInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\PriceRangeInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\ProductInterface;
use Magebit\UcpSpec\Api\Shopping\Types\ProductInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\VariantAvailabilityInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\VariantInterfaceFactory;
use Magebit\UcpSpec\Data\Shopping\Types\Description;
use Magebit\UcpSpec\Data\Shopping\Types\Media;
use Magebit\UcpSpec\Data\Shopping\Types\Price;
use Magebit\UcpSpec\Data\Shopping\Types\PriceRange;
use Magebit\UcpSpec\Data\Shopping\Types\Product;
use Magebit\UcpSpec\Data\Shopping\Types\Variant;
use Magebit\UcpSpec\Data\Shopping\Types\VariantAvailability;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\ProductToUcpProduct;
use Magebit\UniversalCommerce\Model\Service\Shopping\StockAvailability;
use Magento\Catalog\Model\Product as MagentoProduct;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use PHPUnit\Framework\TestCase;

class ProductToUcpProductTest extends TestCase
{
    /**
     * @return void
     */
    public function testCarriesTheRequiredFields(): void
    {
        $product = $this->convert();

        $this->assertSame('ucp-simple', $product->getId());
        $this->assertSame('A Simple Product', $product->getTitle());
        $this->assertNotNull($product->getDescription());
        $this->assertNotNull($product->getPriceRange());
        $this->assertNotEmpty($product->getVariants());
    }

    /**
     * A product with no children is its own single variant, so `variants` is never empty — the spec
     * requires it and an agent buys a variant, not a product.
     *
     * @return void
     */
    public function testASimpleProductIsItsOwnVariant(): void
    {
        $variants = $this->convert()->getVariants();

        $this->assertCount(1, $variants);
        $this->assertSame('ucp-simple', $variants[0]->getId());
        $this->assertSame('ucp-simple', $variants[0]->getSku());
    }

    /**
     * @return void
     */
    public function testPricesAreMinorUnitsWithACurrency(): void
    {
        $price = $this->convert()->getPriceRange()->getMin();

        $this->assertSame(2000, $price->getAmount());
        $this->assertSame('USD', $price->getCurrency());
    }

    /**
     * A single-price product still reports a range, with both ends the same.
     *
     * @return void
     */
    public function testTheRangeCollapsesWhenThereIsOnePrice(): void
    {
        $range = $this->convert()->getPriceRange();

        $this->assertSame($range->getMin()->getAmount(), $range->getMax()->getAmount());
    }

    /**
     * @return void
     */
    public function testAnInStockProductIsAvailable(): void
    {
        $availability = $this->convert()->getVariants()[0]->getAvailability();

        $this->assertTrue($availability->getAvailable());
        $this->assertSame(ProductToUcpProduct::STATUS_IN_STOCK, $availability->getStatus());
    }

    /**
     * @return void
     */
    public function testAnOutOfStockProductIsNotAvailable(): void
    {
        $availability = $this->convert(isSalable: false)->getVariants()[0]->getAvailability();

        $this->assertFalse($availability->getAvailable());
        $this->assertSame(ProductToUcpProduct::STATUS_OUT_OF_STOCK, $availability->getStatus());
    }

    /**
     * The description is a typed object with a plain form, not a bare string.
     *
     * @return void
     */
    public function testTheDescriptionCarriesAPlainForm(): void
    {
        $this->assertSame('Plain words.', $this->convert()->getDescription()->getPlain());
    }

    /**
     * A product with no description still needs one: the field is required.
     *
     * @return void
     */
    public function testAProductWithNoDescriptionStillReportsOne(): void
    {
        $description = $this->convert(description: null)->getDescription();

        $this->assertNotNull($description);
        $this->assertSame('', $description->getPlain());
    }

    /**
     * @param bool $isSalable
     * @param string|null $description
     * @return ProductInterface
     */
    private function convert(bool $isSalable = true, ?string $description = 'Plain words.'): ProductInterface
    {
        $product = $this->getMockBuilder(MagentoProduct::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSku', 'getName', 'getTypeId', 'getFinalPrice', 'getData'])
            ->getMock();
        $product->method('getSku')->willReturn('ucp-simple');
        $product->method('getName')->willReturn('A Simple Product');
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getFinalPrice')->willReturn(20.0);
        $product->method('getData')->willReturnCallback(
            static fn (string $key): mixed => $key === 'description' ? $description : null
        );

        $currency = $this->createMock(PriceCurrencyInterface::class);
        $currency->method('getCurrencySymbol')->willReturn('$');

        return $this->converter($isSalable)->convert($product, 'USD');
    }

    /**
     * @param bool $isSalable
     * @return ProductToUcpProduct
     */
    private function converter(bool $isSalable = true): ProductToUcpProduct
    {
        $stock = $this->createMock(StockAvailability::class);
        $stock->method('isSalable')->willReturn($isSalable);

        return new ProductToUcpProduct(
            $this->factoryFor(ProductInterfaceFactory::class, Product::class),
            $this->factoryFor(VariantInterfaceFactory::class, Variant::class),
            $this->factoryFor(PriceInterfaceFactory::class, Price::class),
            $this->factoryFor(PriceRangeInterfaceFactory::class, PriceRange::class),
            $this->factoryFor(DescriptionInterfaceFactory::class, Description::class),
            $this->factoryFor(MediaInterfaceFactory::class, Media::class),
            $this->factoryFor(VariantAvailabilityInterfaceFactory::class, VariantAvailability::class),
            new MinorUnits(),
            $stock
        );
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
