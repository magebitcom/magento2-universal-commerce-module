<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Api\Service\Shopping;

use Magebit\UcpSpec\Api\Shopping\CatalogLookupGetProductResponseInterface;
use Magebit\UcpSpec\Api\Shopping\CatalogLookupLookupResponseInterface;
use Magebit\UcpSpec\Api\Shopping\CatalogSearchSearchResponseInterface;

/**
 * The catalog search and lookup capabilities. Both are read-only and hold no session.
 */
interface CatalogHandlerInterface
{
    /**
     * @param string|null $query Free-text query
     * @param int|null $limit
     * @param string|null $cursor Opaque page cursor the previous response returned
     * @return CatalogSearchSearchResponseInterface
     */
    public function search(?string $query, ?int $limit = null, ?string $cursor = null): CatalogSearchSearchResponseInterface;

    /**
     * @param string[] $ids SKUs
     * @return CatalogLookupLookupResponseInterface
     */
    public function lookup(array $ids): CatalogLookupLookupResponseInterface;

    /**
     * @param string $id SKU
     * @return CatalogLookupGetProductResponseInterface
     */
    public function getProduct(string $id): CatalogLookupGetProductResponseInterface;
}
