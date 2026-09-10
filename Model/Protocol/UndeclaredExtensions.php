<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\Protocol;

use Magebit\UniversalCommerce\Api\Service\Shopping\CheckoutResponseInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterface;
use Magebit\UcpSpec\Api\Shopping\Types\MessageInterfaceFactory;
use Magebit\UniversalCommerce\Model\Discovery\ServiceRegistry;

/**
 * Says so when a request carries an extension this store does not implement. The specification leaves
 * request objects open so extensions can ride along, which means an unimplemented one would otherwise
 * be dropped in silence — and an agent that sent a signed mandate would go on believing it was checked.
 */
class UndeclaredExtensions
{
    public const CODE_NOT_ACCEPTED = 'not_accepted';

    private const SERVICE_NAME = 'dev.ucp.shopping';

    /**
     * @param ServiceRegistry $serviceRegistry
     * @param MessageInterfaceFactory $messageFactory
     * @param array<string, string> $extensions Request field to the capability that defines it
     */
    public function __construct(
        private readonly ServiceRegistry $serviceRegistry,
        private readonly MessageInterfaceFactory $messageFactory,
        private readonly array $extensions = []
    ) {
    }

    /**
     * @param CheckoutResponseInterface $response
     * @param array<mixed> $body Request body as it arrived
     * @return void
     */
    public function annotate(CheckoutResponseInterface $response, array $body): void
    {
        $warnings = $this->report($body);

        if ($warnings === []) {
            return;
        }

        $response->setMessages(array_merge($response->getMessages() ?? [], $warnings));
    }

    /**
     * @param array<mixed> $body Request body as it arrived
     * @return MessageInterface[]
     */
    public function report(array $body): array
    {
        $declared = $this->declaredCapabilities();
        $warnings = [];

        foreach ($this->extensions as $field => $capability) {
            if (!array_key_exists($field, $body) || in_array($capability, $declared, true)) {
                continue;
            }

            $warnings[] = $this->warning($field, $capability);
        }

        return $warnings;
    }

    /**
     * @return string[]
     */
    private function declaredCapabilities(): array
    {
        $service = $this->serviceRegistry->getService(self::SERVICE_NAME);

        return $service === null ? [] : array_keys($service->getCapabilities());
    }

    /**
     * @param string $field
     * @param string $capability
     * @return MessageInterface
     */
    private function warning(string $field, string $capability): MessageInterface
    {
        /** @var MessageInterface $warning */
        $warning = $this->messageFactory->create(['data' => [
            MessageInterface::KEY_TYPE => 'warning',
            MessageInterface::KEY_CODE => self::CODE_NOT_ACCEPTED,
            MessageInterface::KEY_PATH => '$.' . $field,
            MessageInterface::KEY_CONTENT => sprintf(
                'This store does not implement %s, so the "%s" data in this request was not read or '
                . 'verified. It is not declared in discovery.',
                $capability,
                $field
            ),
        ]]);

        return $warning;
    }
}
