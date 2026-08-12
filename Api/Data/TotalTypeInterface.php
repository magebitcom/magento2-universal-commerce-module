<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2025 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Api\Data;

/**
 * The total types this module emits. UCP `2026-04-08` made `total.type` an open string — it documents
 * these as well-known values in prose and lets businesses add their own — so the vocabulary is no
 * longer generated from the schema and belongs to whoever writes the totals.
 */
interface TotalTypeInterface
{
    public const TYPE_SUBTOTAL = 'subtotal';
    public const TYPE_ITEMS_DISCOUNT = 'items_discount';
    public const TYPE_ITEMS_BASE_AMOUNT = 'items_base_amount';
    public const TYPE_DISCOUNT = 'discount';
    public const TYPE_FULFILLMENT = 'fulfillment';
    public const TYPE_TAX = 'tax';
    public const TYPE_FEE = 'fee';
    public const TYPE_TOTAL = 'total';
}
