<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit\Controller\Testing;

use Magebit\UniversalCommerce\Controller\Testing\SimulateShipping;
use Magebit\UniversalCommerce\Model\Order\SessionOrderLookup;
use Magebit\UniversalCommerce\Model\Simulation\Secret;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json as ResultJson;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SimulateShippingTest extends TestCase
{
    private const SECRET = 'super-secret-sim-key';
    private const CHECKOUT_ID = 'checkout_session_placeholder_0001';
    private const INCREMENT_ID = '000000123';
    private const ENTITY_ID = 42;
    private const SHIPMENT_ID = 7;

    /**
     * A store that put no secret in env.php must look as though the endpoint were never routed.
     *
     * @return void
     */
    public function testTheEndpointIsInvisibleWithoutASecret(): void
    {
        $shipOrder = $this->createMock(ShipOrderInterface::class);
        $shipOrder->expects($this->never())->method('execute');

        $result = $this->controller(configured: null, sent: self::SECRET, shipOrder: $shipOrder)->execute();

        $this->assertSame(404, $result->getHttpResponseCode());
    }

    /**
     * @return void
     */
    public function testAMissingSecretHeaderIsRefused(): void
    {
        $shipOrder = $this->createMock(ShipOrderInterface::class);
        $shipOrder->expects($this->never())->method('execute');

        $result = $this->controller(sent: null, shipOrder: $shipOrder)->execute();

        $this->assertSame(403, $result->getHttpResponseCode());
    }

    /**
     * @return void
     */
    public function testAWrongSecretIsRefused(): void
    {
        $shipOrder = $this->createMock(ShipOrderInterface::class);
        $shipOrder->expects($this->never())->method('execute');

        $result = $this->controller(sent: 'for-sure-incorrect-secret', shipOrder: $shipOrder)->execute();

        $this->assertSame(403, $result->getHttpResponseCode());
    }

    /**
     * @return void
     */
    public function testAnUnknownIdentifierIsNotFound(): void
    {
        $result = $this->controller(found: false)->execute();

        $this->assertSame(404, $result->getHttpResponseCode());
    }

    /**
     * Even with the right secret, this endpoint has no business shipping an order the protocol did not
     * place — the storefront's orders included. Only the checkout session reaches an order, and those
     * orders have none.
     *
     * @return void
     */
    public function testAnOrderPlacedOutsideThisProtocolIsNotShipped(): void
    {
        $shipOrder = $this->createMock(ShipOrderInterface::class);
        $shipOrder->expects($this->never())->method('execute');

        $result = $this->controller(found: false, shipOrder: $shipOrder)->execute();

        $this->assertSame(404, $result->getHttpResponseCode());
    }

    /**
     * @return void
     */
    public function testAnOrderThatCannotShipIsReportedAsAConflict(): void
    {
        $result = $this->controller(canShip: false)->execute();

        $this->assertSame(409, $result->getHttpResponseCode());
    }

    /**
     * @return void
     */
    public function testAnAgentOrderIsShipped(): void
    {
        $shipOrder = $this->createMock(ShipOrderInterface::class);
        $shipOrder->expects($this->once())
            ->method('execute')
            ->with(self::ENTITY_ID)
            ->willReturn(self::SHIPMENT_ID);

        $result = $this->controller(shipOrder: $shipOrder)->execute();

        $this->assertSame(200, $result->getHttpResponseCode());
        $this->assertSame(
            [
                'order_id' => self::CHECKOUT_ID,
                'label' => self::INCREMENT_ID,
                'shipment_id' => self::SHIPMENT_ID,
            ],
            $result->getData()
        );
    }

    /**
     * @return void
     */
    public function testAFailedShipmentIsReportedAsAServerError(): void
    {
        $shipOrder = $this->createMock(ShipOrderInterface::class);
        $shipOrder->method('execute')->willThrowException(new \RuntimeException('no carrier'));

        $result = $this->controller(shipOrder: $shipOrder)->execute();

        $this->assertSame(500, $result->getHttpResponseCode());
    }

    /**
     * @param string|null $configured Secret env.php holds
     * @param string|null $sent Secret the caller sent
     * @param bool $found Whether an order of that increment id exists
     * @param bool $canShip Whether the order is in a shippable state
     * @param ShipOrderInterface|null $shipOrder
     * @return SimulateShipping
     */
    private function controller(
        ?string $configured = self::SECRET,
        ?string $sent = self::SECRET,
        bool $found = true,
        bool $canShip = true,
        ?ShipOrderInterface $shipOrder = null
    ): SimulateShipping {
        $deploymentConfig = $this->createMock(\Magento\Framework\App\DeploymentConfig::class);
        $deploymentConfig->method('get')->with(Secret::CONFIG_PATH)->willReturn($configured);

        $request = $this->createMock(Http::class);
        $request->method('getHeader')->with(SimulateShipping::HEADER_SECRET)->willReturn($sent ?? false);
        $request->method('getParam')->with('order_id')->willReturn(self::CHECKOUT_ID);

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEntityId', 'canShip', 'getIncrementId'])
            ->getMock();
        $order->method('getEntityId')->willReturn(self::ENTITY_ID);
        $order->method('canShip')->willReturn($canShip);
        $order->method('getIncrementId')->willReturn(self::INCREMENT_ID);

        $lookup = $this->createMock(SessionOrderLookup::class);
        $lookup->method('find')->willReturn($found ? $order : null);

        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturnCallback(fn (): ResultJson => $this->resultJson());

        return new SimulateShipping(
            $jsonFactory,
            $request,
            new Secret($deploymentConfig),
            $lookup,
            $shipOrder ?? $this->createMock(ShipOrderInterface::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * A result that remembers what was set on it, so the status code can be asserted.
     *
     * @return ResultJson&MockObject
     */
    private function resultJson(): ResultJson
    {
        $state = ['data' => null, 'code' => 200];

        $json = $this->getMockBuilder(ResultJson::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setData', 'setHttpResponseCode'])
            ->addMethods(['getData', 'getHttpResponseCode'])
            ->getMock();

        $json->method('setData')->willReturnCallback(function ($data) use (&$state, $json) {
            $state['data'] = $data;

            return $json;
        });
        $json->method('getData')->willReturnCallback(function () use (&$state) {
            return $state['data'];
        });
        $json->method('setHttpResponseCode')->willReturnCallback(function ($code) use (&$state, $json) {
            $state['code'] = (int) $code;

            return $json;
        });
        $json->method('getHttpResponseCode')->willReturnCallback(function () use (&$state): int {
            return $state['code'];
        });

        return $json;
    }
}
