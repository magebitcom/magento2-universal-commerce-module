<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\Discovery;

use Magebit\UcpSpec\Api\Discovery\ProfileSchemaInterface as DiscoveryProfileInterface;
use Magebit\UcpSpec\Api\Discovery\ProfileSchemaInterfaceFactory as DiscoveryProfileInterfaceFactory;
use Magebit\UcpSpec\Api\Discovery\ProfileSchemaSigningKeyInterface;
use Magebit\UcpSpec\Api\Discovery\ProfileSchemaSigningKeyInterfaceFactory;

use Magebit\UcpSpec\Api\UcpBusinessSchemaInterface as UcpProfileInterface;
use Magebit\UcpSpec\Api\UcpBusinessSchemaInterfaceFactory as UcpProfileInterfaceFactory;
use Magebit\UcpSpec\Api\ServiceBusinessSchemaInterface;
use Magebit\UcpSpec\Api\CapabilityBusinessSchemaInterface;
use Magebit\AgenticCore\Api\SigningKeyRepositoryInterface;
use Magebit\UniversalCommerce\Api\ServiceInterface;
use Magebit\UniversalCommerce\Api\UniversalCommerceProtocolInterface;
use Magebit\UniversalCommerce\Model\IdempotencyHandler;

class MerchantProfileBuilder
{
    /**
     * One profile is served per site, so the key set lives in the default scope.
     */
    private const KEY_STORE_ID = 0;

    /**
     * @param DiscoveryProfileInterfaceFactory $discoveryProfileFactory
     * @param UcpProfileInterfaceFactory $ucpProfileFactory
     * @param ServiceRegistry $serviceRegistry
     * @param SigningKeyRepositoryInterface $signingKeys
     * @param ProfileSchemaSigningKeyInterfaceFactory $jwkFactory
     */
    public function __construct(
        private readonly DiscoveryProfileInterfaceFactory $discoveryProfileFactory,
        private readonly UcpProfileInterfaceFactory $ucpProfileFactory,
        private readonly ServiceRegistry $serviceRegistry,
        private readonly SigningKeyRepositoryInterface $signingKeys,
        private readonly ProfileSchemaSigningKeyInterfaceFactory $jwkFactory,
    ) {
    }

    /**
     * @return DiscoveryProfileInterface
     */
    public function build(): DiscoveryProfileInterface
    {
        return $this->discoveryProfileFactory->create([
            'data' => [
                DiscoveryProfileInterface::KEY_UCP => $this->buildUcp(),
                DiscoveryProfileInterface::KEY_SIGNING_KEYS => $this->buildSigningKeys(),
            ]
        ]);
    }

    /**
     * The public halves a platform needs to verify what we sign. Retired keys stay listed through their
     * grace window, so a delivery signed just before a rotation still verifies.
     *
     * @return ProfileSchemaSigningKeyInterface[]
     */
    private function buildSigningKeys(): array
    {
        // Generated here if it does not exist yet: an agent reads the profile before it ever receives a
        // webhook, and a profile with no key gives it nothing to verify against later.
        $this->signingKeys->getSigningKey(IdempotencyHandler::SCOPE, self::KEY_STORE_ID);

        $jwks = [];

        foreach ($this->signingKeys->getPublishableKeys(IdempotencyHandler::SCOPE, self::KEY_STORE_ID) as $key) {
            $jwk = $key->getPublicJwk();

            if ($jwk === [] || !isset($jwk[ProfileSchemaSigningKeyInterface::KEY_KID])) {
                continue;
            }

            $jwks[] = $this->jwkFactory->create(['data' => $jwk]);
        }

        return $jwks;
    }

    /**
     * @return UcpProfileInterface
     */
    public function buildUcp(): UcpProfileInterface
    {
        $registeredServices = $this->serviceRegistry->getServices();
        $services = [];
        $capabilities = [];

        foreach ($registeredServices as $name => $service) {
            $serviceObj = $service->getService();

            if (!isset($services[$name])) {
                $services[$name] = [];
            }

            $services[$name][] = $serviceObj;

            $serviceCapabilities = $service->getCapabilities();

            foreach ($serviceCapabilities as $capName => $capArray) {
                if (!isset($capabilities[$capName])) {
                    $capabilities[$capName] = [];
                }

                $capabilities[$capName] = array_merge($capabilities[$capName], $capArray);
            }
        }

        return $this->ucpProfileFactory->create([
            'data' => [
                UcpProfileInterface::KEY_VERSION => UniversalCommerceProtocolInterface::SPEC_VERSION,
                UcpProfileInterface::KEY_SERVICES => $services,
                UcpProfileInterface::KEY_CAPABILITIES => $capabilities,
                UcpProfileInterface::KEY_PAYMENT_HANDLERS => [],
            ]
        ]);
    }
}
