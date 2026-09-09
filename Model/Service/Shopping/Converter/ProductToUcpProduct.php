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

use Magebit\AgenticCore\Model\Money\MinorUnits;
use Magebit\UcpSpec\Api\Shopping\Types\DescriptionInterface;
use Magebit\UcpSpec\Api\Shopping\Types\DescriptionInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\MediaInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MediaInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\PriceInterface;
use Magebit\UcpSpec\Api\Shopping\Types\PriceInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\PriceRangeInterface;
use Magebit\UcpSpec\Api\Shopping\Types\PriceRangeInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\ProductInterface;
use Magebit\UcpSpec\Api\Shopping\Types\ProductInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\VariantAvailabilityInterface;
use Magebit\UcpSpec\Api\Shopping\Types\VariantAvailabilityInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\VariantInterface;
use Magebit\UcpSpec\Api\Shopping\Types\VariantInterfaceFactory;
use Magebit\AgenticCore\Model\Stock\Availability;
use Magento\Catalog\Model\Product as MagentoProduct;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;

/**
 * Builds the spec's catalog product from a Magento one. An agent buys a variant, so a product with no
 * children is reported as its own single variant.
 */
class ProductToUcpProduct
{
    public const STATUS_IN_STOCK = 'in_stock';
    public const STATUS_OUT_OF_STOCK = 'out_of_stock';

    private const MEDIA_TYPE_IMAGE = 'image';

    /**
     * @param ProductInterfaceFactory $productFactory
     * @param VariantInterfaceFactory $variantFactory
     * @param PriceInterfaceFactory $priceFactory
     * @param PriceRangeInterfaceFactory $priceRangeFactory
     * @param DescriptionInterfaceFactory $descriptionFactory
     * @param MediaInterfaceFactory $mediaFactory
     * @param VariantAvailabilityInterfaceFactory $availabilityFactory
     * @param MinorUnits $minorUnits
     * @param Availability $stock
     */
    public function __construct(
        private readonly ProductInterfaceFactory $productFactory,
        private readonly VariantInterfaceFactory $variantFactory,
        private readonly PriceInterfaceFactory $priceFactory,
        private readonly PriceRangeInterfaceFactory $priceRangeFactory,
        private readonly DescriptionInterfaceFactory $descriptionFactory,
        private readonly MediaInterfaceFactory $mediaFactory,
        private readonly VariantAvailabilityInterfaceFactory $availabilityFactory,
        private readonly MinorUnits $minorUnits,
        private readonly Availability $stock
    ) {
    }

    /**
     * @param MagentoProduct $product
     * @param string $currencyCode
     * @param MagentoProduct[] $children Variants, when the product has any
     * @param string|null $url
     * @param string|null $imageUrl
     * @return ProductInterface
     */
    public function convert(
        MagentoProduct $product,
        string $currencyCode,
        array $children = [],
        ?string $url = null,
        ?string $imageUrl = null
    ): ProductInterface {
        $variants = $this->variantsFor($product, $currencyCode, $children);

        /** @var ProductInterface $result */
        $result = $this->productFactory->create();
        $result->setId((string) $product->getSku());
        $result->setTitle((string) $product->getName());
        $result->setDescription($this->descriptionFor($product));
        $result->setPriceRange($this->rangeOver($variants, $currencyCode));
        $result->setVariants($variants);

        if ($url !== null) {
            $result->setUrl($url);
        }

        if ($imageUrl !== null) {
            $result->setMedia([$this->mediaFor($imageUrl)]);
        }

        return $result;
    }

    /**
     * @param MagentoProduct $product
     * @param string $currencyCode
     * @param MagentoProduct[] $children
     * @return VariantInterface[]
     */
    private function variantsFor(MagentoProduct $product, string $currencyCode, array $children): array
    {
        // Configurable products are bought through a child; everything else is bought as itself, and the
        // spec requires at least one variant either way.
        $sources = $children !== [] || $product->getTypeId() === Configurable::TYPE_CODE
            ? $children
            : [$product];

        $variants = [];

        foreach ($sources as $source) {
            $variants[] = $this->variantFor($source, $currencyCode);
        }

        return $variants;
    }

    /**
     * @param MagentoProduct $product
     * @param string $currencyCode
     * @return VariantInterface
     */
    private function variantFor(MagentoProduct $product, string $currencyCode): VariantInterface
    {
        /** @var VariantInterface $variant */
        $variant = $this->variantFactory->create();
        $variant->setId((string) $product->getSku());
        $variant->setSku((string) $product->getSku());
        $variant->setTitle((string) $product->getName());
        $variant->setDescription($this->descriptionFor($product));
        $variant->setPrice($this->priceFor($this->finalPriceOf($product), $currencyCode));
        $variant->setAvailability($this->availabilityFor($product));

        return $variant;
    }

    /**
     * @param MagentoProduct $product
     * @return VariantAvailabilityInterface
     */
    private function availabilityFor(MagentoProduct $product): VariantAvailabilityInterface
    {
        $available = $this->stock->isSalable((string) $product->getSku());

        /** @var VariantAvailabilityInterface $availability */
        $availability = $this->availabilityFactory->create();
        $availability->setAvailable($available);
        $availability->setStatus($available ? self::STATUS_IN_STOCK : self::STATUS_OUT_OF_STOCK);

        return $availability;
    }

    /**
     * @param VariantInterface[] $variants
     * @param string $currencyCode
     * @return PriceRangeInterface
     */
    private function rangeOver(array $variants, string $currencyCode): PriceRangeInterface
    {
        $amounts = [];

        foreach ($variants as $variant) {
            $amounts[] = $variant->getPrice()->getAmount();
        }

        $amounts = $amounts === [] ? [0] : $amounts;

        /** @var PriceRangeInterface $range */
        $range = $this->priceRangeFactory->create();
        $range->setMin($this->priceFromMinorUnits(min($amounts), $currencyCode));
        $range->setMax($this->priceFromMinorUnits(max($amounts), $currencyCode));

        return $range;
    }

    /**
     * @param MagentoProduct $product
     * @return float
     */
    private function finalPriceOf(MagentoProduct $product): float
    {
        return (float) $product->getFinalPrice();
    }

    /**
     * @param float $amount
     * @param string $currencyCode
     * @return PriceInterface
     */
    private function priceFor(float $amount, string $currencyCode): PriceInterface
    {
        return $this->priceFromMinorUnits($this->minorUnits->convert($amount, $currencyCode), $currencyCode);
    }

    /**
     * @param int $minorUnits
     * @param string $currencyCode
     * @return PriceInterface
     */
    private function priceFromMinorUnits(int $minorUnits, string $currencyCode): PriceInterface
    {
        /** @var PriceInterface $price */
        $price = $this->priceFactory->create();
        $price->setAmount($minorUnits);
        $price->setCurrency($currencyCode);

        return $price;
    }

    /**
     * The field is required, so a product without one reports an empty description rather than omitting it.
     *
     * @param MagentoProduct $product
     * @return DescriptionInterface
     */
    private function descriptionFor(MagentoProduct $product): DescriptionInterface
    {
        $raw = $product->getData('description');
        $plain = is_string($raw) ? trim(strip_tags($raw)) : '';

        /** @var DescriptionInterface $description */
        $description = $this->descriptionFactory->create();
        $description->setPlain($plain);

        return $description;
    }

    /**
     * @param string $url
     * @return MediaInterface
     */
    private function mediaFor(string $url): MediaInterface
    {
        /** @var MediaInterface $media */
        $media = $this->mediaFactory->create();
        $media->setType(self::MEDIA_TYPE_IMAGE);
        $media->setUrl($url);

        return $media;
    }
}
