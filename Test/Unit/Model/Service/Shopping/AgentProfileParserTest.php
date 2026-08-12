<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit\Model\Service\Shopping;

use Magebit\UcpSpec\Api\Shopping\OrderResponsePlatformSchemaInterfaceFactory;
use Magebit\UcpSpec\Data\Shopping\OrderResponsePlatformSchema;
use Magebit\UniversalCommerce\Model\Service\Shopping\AgentProfileParser;
use Magebit\UniversalCommerce\Model\Service\Shopping\ProfileUrlValidator;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AgentProfileParserTest extends TestCase
{
    private const WEBHOOK_URL = 'https://agent.test/hook';

    /** @var AgentProfileParser */
    private AgentProfileParser $parser;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $factory = $this->createMock(OrderResponsePlatformSchemaInterfaceFactory::class);
        $factory->method('create')
            ->willReturnCallback(fn (): OrderResponsePlatformSchema => new OrderResponsePlatformSchema());

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);

        $this->parser = new AgentProfileParser(
            $factory,
            $this->createMock(CurlFactory::class),
            $cache,
            $this->createMock(LoggerInterface::class),
            $this->createMock(ProfileUrlValidator::class)
        );
    }

    /**
     * `capabilities` is an object keyed by reverse-domain name whose values are arrays of entries, so the
     * name is the key and no entry carries one. Looking for a `name` field inside each value could never
     * match a conformant platform profile.
     *
     * @return void
     */
    public function testTheWebhookUrlIsReadFromTheKeyedCapabilityRegistry(): void
    {
        $this->assertSame(self::WEBHOOK_URL, $this->parser->parseWebhookUrl($this->header([
            'ucp' => [
                'version' => '2026-04-08',
                'capabilities' => [
                    'dev.ucp.shopping.order' => [
                        ['version' => '2026-04-08', 'config' => ['webhook_url' => self::WEBHOOK_URL]],
                    ],
                ],
            ],
        ])));
    }

    /**
     * A platform may declare several entries for one capability; the first that configures a URL wins.
     *
     * @return void
     */
    public function testTheFirstConfiguredEntryWins(): void
    {
        $this->assertSame(self::WEBHOOK_URL, $this->parser->parseWebhookUrl($this->header([
            'ucp' => [
                'capabilities' => [
                    'dev.ucp.shopping.order' => [
                        ['version' => '2026-04-08'],
                        ['version' => '2026-04-08', 'config' => ['webhook_url' => self::WEBHOOK_URL]],
                    ],
                ],
            ],
        ])));
    }

    /**
     * Most platforms declare no order capability, which is not an error.
     *
     * @return void
     */
    public function testAProfileWithoutTheOrderCapabilityYieldsNothing(): void
    {
        $this->assertNull($this->parser->parseWebhookUrl($this->header([
            'ucp' => ['capabilities' => ['dev.ucp.shopping.checkout' => [['version' => '2026-04-08']]]],
        ])));
    }

    /**
     * @return void
     */
    public function testNoHeaderYieldsNothing(): void
    {
        $this->assertNull($this->parser->parseWebhookUrl(null));
        $this->assertNull($this->parser->parseWebhookUrl('profile="not-a-uri"'));
    }

    /**
     * The generated field is required-typed, so an absent URL has to be left unset rather than written
     * as null.
     *
     * @return void
     */
    public function testAnAbsentUrlLeavesTheFieldUnset(): void
    {
        $schema = $this->parser->parse($this->header(['ucp' => ['capabilities' => []]]));

        $this->assertFalse($schema->has(OrderResponsePlatformSchema::KEY_WEBHOOK_URL));
    }

    /**
     * @param array<string, mixed> $profile
     * @return string A UCP-Agent header carrying the profile inline, which the parser supports
     */
    private function header(array $profile): string
    {
        return sprintf(
            'profile="data:application/json;base64,%s"',
            base64_encode((string) json_encode($profile))
        );
    }
}
