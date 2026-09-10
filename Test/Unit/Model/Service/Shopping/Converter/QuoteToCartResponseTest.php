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

use Magebit\UcpSpec\Api\Shopping\Types\MessageInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInfoInterface;
use Magebit\UcpSpec\Api\UcpResponseCartSchemaInterface;
use Magebit\UcpSpec\Data\Shopping\Types\Message;
use Magebit\UcpSpec\Data\Shopping\Types\MessageInfo;
use Magebit\UcpSpec\Data\UcpResponseCartSchema;
use Magebit\UniversalCommerce\Model\Service\Shopping\Converter\CartMessageDowngrade;
use PHPUnit\Framework\TestCase;

class QuoteToCartResponseTest extends TestCase
{
    /**
     * A cart is pre-purchase exploration, so a missing delivery address is a warning about what checkout
     * will need — not an error about the cart.
     *
     * @return void
     */
    public function testCheckoutValidationErrorsBecomeInformational(): void
    {
        $error = new Message([
            MessageInterface::KEY_TYPE => MessageInterface::TYPE_ERROR,
            MessageInterface::KEY_CODE => 'missing',
            MessageInterface::KEY_CONTENT => 'City is required',
            MessageInterface::KEY_SEVERITY => MessageInterface::SEVERITY_REQUIRES_BUYER_INPUT,
            MessageInterface::KEY_PATH => '$.fulfillment.methods[0].destination.address_locality',
        ]);

        $messages = $this->downgrade()->apply([$error]);

        $this->assertCount(1, $messages);
        $this->assertSame(MessageInfoInterface::TYPE_INFO, $messages[0]->getType());
    }

    /**
     * @return void
     */
    public function testTheOriginalContentAndPathSurvive(): void
    {
        $error = new Message([
            MessageInterface::KEY_TYPE => MessageInterface::TYPE_ERROR,
            MessageInterface::KEY_CODE => 'missing',
            MessageInterface::KEY_CONTENT => 'City is required',
            MessageInterface::KEY_SEVERITY => MessageInterface::SEVERITY_REQUIRES_BUYER_INPUT,
            MessageInterface::KEY_PATH => '$.buyer.first_name',
        ]);

        $message = $this->downgrade()->apply([$error])[0];

        $this->assertSame('City is required', $message->getContent());
        $this->assertSame('$.buyer.first_name', $message->getPath());
    }

    /**
     * A message the cart itself raised is already informational and is passed through untouched.
     *
     * @return void
     */
    public function testAnInformationalMessageIsLeftAlone(): void
    {
        $info = new MessageInfo([
            MessageInfoInterface::KEY_TYPE => MessageInfoInterface::TYPE_INFO,
            MessageInfoInterface::KEY_CONTENT => 'Estimated totals',
        ]);

        $messages = $this->downgrade()->apply([$info]);

        $this->assertSame($info, $messages[0]);
    }

    /**
     * @return void
     */
    public function testNoMessagesStayNoMessages(): void
    {
        $this->assertSame([], $this->downgrade()->apply([]));
    }

    /**
     * `response_cart_schema` carries no payment handlers: there is no payment before checkout.
     *
     * @return void
     */
    public function testTheCartUcpBlockCarriesNoPaymentHandlers(): void
    {
        $ucp = new UcpResponseCartSchema([
            UcpResponseCartSchemaInterface::KEY_VERSION => '2026-04-08',
            UcpResponseCartSchemaInterface::KEY_CAPABILITIES => [],
        ]);

        $this->assertFalse($ucp->has('payment_handlers'));
    }

    /**
     * @return CartMessageDowngrade
     */
    private function downgrade(): CartMessageDowngrade
    {
        $factory = $this->createMock(\Magebit\UcpSpec\Api\Shopping\Types\MessageInfoInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn (array $args): MessageInfo => new MessageInfo($args['data'])
        );

        return new CartMessageDowngrade($factory);
    }
}
