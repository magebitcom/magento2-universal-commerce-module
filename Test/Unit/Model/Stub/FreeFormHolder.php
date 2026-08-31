<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit\Model\Stub;

/**
 * Stands in for a spec type with a free-form map, the way a payment instrument's `display` is.
 */
class FreeFormHolder
{
    /**
     * @var array<mixed>|null
     */
    private ?array $display = null;

    /**
     * @return array<mixed>|null
     */
    public function getDisplay(): ?array
    {
        return $this->display;
    }

    /**
     * @param array<mixed>|null $display
     * @return self
     */
    public function setDisplay(?array $display): self
    {
        $this->display = $display;

        return $this;
    }
}
