<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model;

/**
 * Every timestamp the spec carries is RFC 3339, and every timestamp Magento stores is a UTC MySQL
 * datetime. This is the one place that translates between them.
 */
class Timestamp
{
    /**
     * @param string|null $databaseValue
     * @return string|null Null when there is no timestamp to report
     */
    public function toRfc3339(?string $databaseValue): ?string
    {
        if ($databaseValue === null || trim($databaseValue) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($databaseValue, new \DateTimeZone('UTC')))
                ->format(\DateTimeInterface::RFC3339);
        } catch (\Exception $exception) {
            return null;
        }
    }
}
