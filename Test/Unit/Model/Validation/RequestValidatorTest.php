<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit\Model\Validation;

use Magebit\UcpSpec\Api\Shopping\Types\AdjustmentInterface;
use Magebit\UniversalCommerce\Model\Validation\RequestValidator;
use PHPUnit\Framework\TestCase;

class RequestValidatorTest extends TestCase
{
    private RequestValidator $validator;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->validator = new RequestValidator();
    }

    /**
     * @return void
     */
    public function testAValueTheSpecificationAllowsPasses(): void
    {
        $result = $this->validator->validate($this->adjustment('pending'), AdjustmentInterface::class);

        $this->assertTrue($result->isValid());
    }

    /**
     * The generated interfaces name every allowed value, so a value outside the list is caught without
     * anything here restating the specification.
     *
     * @return void
     */
    public function testAValueOutsideTheSpecificationsListIsRejected(): void
    {
        $result = $this->validator->validate($this->adjustment('INVALID_STATUS'), AdjustmentInterface::class);

        $this->assertFalse($result->isValid());
        $this->assertArrayHasKey('status', $result->getErrors());
        $this->assertStringContainsString('pending', $result->getErrors()['status']);
    }

    /**
     * `adjustment.type` is an open string in the specification, which is why the interface names no
     * values for it. A field like that must not be second-guessed.
     *
     * @return void
     */
    public function testAFieldTheSpecificationLeavesOpenAcceptsAnything(): void
    {
        $data = $this->adjustment('pending');
        $data['type'] = 'something-a-merchant-invented';

        $this->assertTrue($this->validator->validate($data, AdjustmentInterface::class)->isValid());
    }

    /**
     * @return void
     */
    public function testAMissingRequiredFieldIsReported(): void
    {
        $data = $this->adjustment('pending');
        unset($data['occurred_at']);

        $result = $this->validator->validate($data, AdjustmentInterface::class);

        $this->assertFalse($result->isValid());
        $this->assertArrayHasKey('occurred_at', $result->getErrors());
    }

    /**
     * @return void
     */
    public function testAListSentAsSomethingElseIsReported(): void
    {
        $data = $this->adjustment('pending');
        $data['totals'] = 'not-a-list';

        $result = $this->validator->validate($data, AdjustmentInterface::class);

        $this->assertFalse($result->isValid());
        $this->assertArrayHasKey('totals', $result->getErrors());
    }

    /**
     * @param string $status
     * @return array<string, mixed>
     */
    private function adjustment(string $status): array
    {
        return [
            'id' => 'adj_1',
            'type' => 'refund',
            'occurred_at' => '2026-08-31T12:00:00Z',
            'status' => $status,
        ];
    }
}
