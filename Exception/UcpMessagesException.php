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

use Magebit\UcpSpec\Api\Shopping\Types\MessageInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * A failure with more than one thing to say: the boundary reports every message, so an agent is told
 * each field it has to fix rather than only the first one the store tripped over.
 */
class UcpMessagesException extends LocalizedException
{
    /**
     * @param Phrase $phrase
     * @param MessageInterface[] $messages
     * @param int $statusCode
     */
    public function __construct(
        Phrase $phrase,
        public readonly array $messages,
        public readonly int $statusCode = 422
    ) {
        parent::__construct($phrase);
    }

    /**
     * @return MessageInterface[]
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
