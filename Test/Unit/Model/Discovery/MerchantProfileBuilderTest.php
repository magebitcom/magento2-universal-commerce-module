<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit\Model\Discovery;

use Magebit\AgenticCore\Api\Data\SigningKeyInterface;
use Magebit\AgenticCore\Api\SigningKeyRepositoryInterface;
use Magebit\UcpSpec\Api\Discovery\ProfileSchemaInterfaceFactory;
use Magebit\UcpSpec\Api\Discovery\ProfileSchemaSigningKeyInterfaceFactory;
use Magebit\UcpSpec\Data\Discovery\ProfileSchemaSigningKey;
use Magebit\UcpSpec\Api\UcpBusinessSchemaInterfaceFactory;
use Magebit\UcpSpec\Data\Discovery\ProfileSchema;
use Magebit\UcpSpec\Data\UcpBusinessSchema;
use Magebit\UniversalCommerce\Model\Discovery\MerchantProfileBuilder;
use Magebit\UniversalCommerce\Model\Discovery\ServiceRegistry;
use PHPUnit\Framework\TestCase;

class MerchantProfileBuilderTest extends TestCase
{
    private const JWK = [
        'kty' => 'EC',
        'crv' => 'P-256',
        'x' => 'f83OJ3D2xF1Bg8vub9tLe1gHMzV76e8Tus9uPHvRVEU',
        'y' => 'x_FEzRu9m36HLN_tue659LNpXW6pCyStikYjKIWI5a0',
        'kid' => 'merchant-2026',
        'use' => 'sig',
        'alg' => 'ES256',
    ];

    /**
     * A verifier cannot check a signed webhook without the key, so publishing it is not optional.
     *
     * @return void
     */
    public function testPublishesTheSigningKey(): void
    {
        $profile = $this->build([$this->key(self::JWK)]);

        $published = $profile->getSigningKeys();

        $this->assertCount(1, $published);
        $this->assertSame('merchant-2026', $published[0]->getKid());
        $this->assertSame('P-256', $published[0]->getCrv());
        $this->assertSame('ES256', $published[0]->getAlg());
    }

    /**
     * Rotation publishes the new key alongside the retired one so signatures already in flight keep
     * verifying through the grace window.
     *
     * @return void
     */
    public function testPublishesEveryKeyAVerifierMayStillNeed(): void
    {
        $retired = ['kid' => 'merchant-2025'] + self::JWK;
        $profile = $this->build([$this->key(self::JWK), $this->key($retired)]);

        $published = $profile->getSigningKeys();

        $this->assertCount(2, $published);
        $this->assertSame(
            ['merchant-2026', 'merchant-2025'],
            array_map(static fn ($jwk): string => $jwk->getKid(), $published)
        );
    }

    /**
     * @return void
     */
    public function testNeverPublishesAPrivateHalf(): void
    {
        $profile = $this->build([$this->key(self::JWK)]);

        foreach ($profile->getSigningKeys() as $jwk) {
            $this->assertArrayNotHasKey('d', $jwk->toArray());
        }
    }

    /**
     * A key row whose stored JWK cannot be read is skipped rather than published as an empty object,
     * which a verifier would try to use and fail on.
     *
     * @return void
     */
    public function testSkipsAKeyWithNoReadableJwk(): void
    {
        $profile = $this->build([$this->key([]), $this->key(self::JWK)]);
        $published = $profile->getSigningKeys();

        $this->assertCount(1, $published);
        $this->assertSame('merchant-2026', $published[0]->getKid());
    }

    /**
     * An agent fetches the profile before any webhook arrives, so the key has to exist by then.
     *
     * @return void
     */
    public function testCreatesTheKeyIfTheProfileIsAskedForFirst(): void
    {
        $repository = $this->createMock(SigningKeyRepositoryInterface::class);
        $repository->expects($this->once())->method('getSigningKey')->willReturn($this->key(self::JWK));
        $repository->method('getPublishableKeys')->willReturn([$this->key(self::JWK)]);

        $this->buildWith($repository);
    }

    /**
     * @param SigningKeyInterface[] $keys
     * @return ProfileSchema
     */
    private function build(array $keys): ProfileSchema
    {
        $profileFactory = $this->createMock(ProfileSchemaInterfaceFactory::class);
        $profileFactory->method('create')->willReturnCallback(
            static fn (array $args): ProfileSchema => new ProfileSchema($args['data'])
        );

        $ucpFactory = $this->createMock(UcpBusinessSchemaInterfaceFactory::class);
        $ucpFactory->method('create')->willReturnCallback(
            static fn (array $args): UcpBusinessSchema => new UcpBusinessSchema($args['data'])
        );

        $registry = $this->createMock(ServiceRegistry::class);
        $registry->method('getServices')->willReturn([]);

        $repository = $this->createMock(SigningKeyRepositoryInterface::class);
        $repository->method('getPublishableKeys')->willReturn($keys);
        $repository->method('getSigningKey')->willReturn($this->key(self::JWK));

        return $this->buildWith($repository);
    }

    /**
     * @param SigningKeyRepositoryInterface $repository
     * @return ProfileSchema
     */
    private function buildWith(SigningKeyRepositoryInterface $repository): ProfileSchema
    {
        $profileFactory = $this->createMock(ProfileSchemaInterfaceFactory::class);
        $profileFactory->method('create')->willReturnCallback(
            static fn (array $args): ProfileSchema => new ProfileSchema($args['data'])
        );

        $ucpFactory = $this->createMock(UcpBusinessSchemaInterfaceFactory::class);
        $ucpFactory->method('create')->willReturnCallback(
            static fn (array $args): UcpBusinessSchema => new UcpBusinessSchema($args['data'])
        );

        $registry = $this->createMock(ServiceRegistry::class);
        $registry->method('getServices')->willReturn([]);

        $jwkFactory = $this->createMock(ProfileSchemaSigningKeyInterfaceFactory::class);
        $jwkFactory->method('create')->willReturnCallback(
            static fn (array $args): ProfileSchemaSigningKey => new ProfileSchemaSigningKey($args['data'])
        );

        return (new MerchantProfileBuilder(
            $profileFactory,
            $ucpFactory,
            $registry,
            $repository,
            $jwkFactory
        ))->build();
    }

    /**
     * @param array<string, string> $jwk
     * @return SigningKeyInterface
     */
    private function key(array $jwk): SigningKeyInterface
    {
        $key = $this->createMock(SigningKeyInterface::class);
        $key->method('getPublicJwk')->willReturn($jwk);

        return $key;
    }
}
