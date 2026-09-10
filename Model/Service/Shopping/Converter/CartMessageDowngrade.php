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

use Magebit\UcpSpec\Api\Shopping\Types\MessageInfoInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInfoInterfaceFactory;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterface;

/**
 * Reports checkout validation errors as warnings on a cart. The spec has a cart return informational
 * messages: a missing delivery address is something checkout will need, not a fault in the cart.
 */
class CartMessageDowngrade
{
    /**
     * @param MessageInfoInterfaceFactory $messageInfoFactory
     */
    public function __construct(
        private readonly MessageInfoInterfaceFactory $messageInfoFactory
    ) {
    }

    /**
     * Keyed on the message's own `type` rather than its PHP class: the validators build the generic
     * message type, and the generated error and info types share no interface.
     *
     * @param array<int, object> $messages
     * @return array<int, object>
     */
    public function apply(array $messages): array
    {
        return array_map(fn (object $message): object => $this->downgrade($message), $messages);
    }

    /**
     * @param object $message
     * @return object
     */
    private function downgrade(object $message): object
    {
        if (!$message instanceof MessageInterface || $message->getType() !== MessageInterface::TYPE_ERROR) {
            return $message;
        }

        return $this->messageInfoFactory->create([
            'data' => array_filter([
                MessageInfoInterface::KEY_TYPE => MessageInfoInterface::TYPE_INFO,
                MessageInfoInterface::KEY_CODE => $message->getCode(),
                MessageInfoInterface::KEY_CONTENT => $message->getContent(),
                MessageInfoInterface::KEY_CONTENT_TYPE => $message->getContentType(),
                MessageInfoInterface::KEY_PATH => $message->getPath(),
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }
}
