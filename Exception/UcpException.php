<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\UniversalCommerce\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * A failure the boundary can report as the spec's error response: the error code names the failure and
 * the HTTP status carries the transport tier. Named `errorCode` because `Exception::getCode()` is final
 * and typed int.
 */
class UcpException extends LocalizedException
{
    public function __construct(
        Phrase $phrase,
        public readonly string $errorCode = 'server_error',
        public readonly int $statusCode = 500
    ) {
        parent::__construct($phrase);
    }

    /**
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return string
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
