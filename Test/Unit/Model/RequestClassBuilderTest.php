<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit\Model;

use Magebit\UniversalCommerce\Model\RequestClassBuilder;
use Magebit\UniversalCommerce\Test\Unit\Model\Stub\FreeFormHolder;
use Magento\Framework\Api\ObjectFactory;
use Magento\Framework\Reflection\MethodsMap;
use Magento\Framework\Reflection\TypeProcessor;
use PHPUnit\Framework\TestCase;

class RequestClassBuilderTest extends TestCase
{
    /**
     * The spec leaves some maps open — a payment instrument's `display` is one — and a getter typed as a
     * plain array is the only sign of it. Building one into an object asks the object manager for a class
     * called "array", which is a fatal error on every request that carries the field.
     *
     * @dataProvider freeFormTypeProvider
     * @param string $returnType What the getter's docblock says
     * @return void
     */
    public function testAFreeFormMapIsPassedThrough(string $returnType): void
    {
        $objectFactory = $this->createMock(ObjectFactory::class);
        $objectFactory->expects($this->never())->method('create');

        $methodsMap = $this->createMock(MethodsMap::class);
        $methodsMap->method('getMethodReturnType')->willReturn($returnType);

        $holder = new FreeFormHolder();
        $value = ['brand' => 'Visa', 'last_digits' => '1234'];

        $builder = new RequestClassBuilder($objectFactory, new TypeProcessor(), $methodsMap);
        $builder->populateWithArray($holder, ['display' => $value], FreeFormHolder::class);

        $this->assertSame($value, $holder->getDisplay());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function freeFormTypeProvider(): array
    {
        return [
            'plain array' => ['array'],
            'generic array' => ['array<mixed>'],
            'mixed' => ['mixed'],
        ];
    }
}
