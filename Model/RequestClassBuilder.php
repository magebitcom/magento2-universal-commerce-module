<?php

/**
 * @author Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license https://magebit.com/code-license
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model;

use Magento\Framework\Api\ObjectFactory;
use Magento\Framework\Api\SimpleDataObjectConverter;
use Magento\Framework\Reflection\MethodsMap;
use Magento\Framework\Reflection\TypeProcessor;

/**
 * Service class for populating DataObject-based request classes from array data.
 * Simplified version of DataObjectHelper without ExtensibleDataInterface handling.
 */
class RequestClassBuilder
{
    /**
     * @var array<string, array<string, int>>
     */
    private array $settersCache = [];

    /**
     * @param ObjectFactory $objectFactory
     * @param TypeProcessor $typeProcessor
     * @param MethodsMap $methodsMapProcessor
     */
    public function __construct(
        private readonly ObjectFactory $objectFactory,
        private readonly TypeProcessor $typeProcessor,
        private readonly MethodsMap $methodsMapProcessor
    ) {
    }

    /**
     * Populate data object using data in array format.
     *
     * @param object $dataObject
     * @param array<mixed> $data
     * @param string $interfaceName
     * @return $this
     */
    public function populateWithArray(object $dataObject, array $data, string $interfaceName): self
    {
        $this->_setDataValues($dataObject, $data, $interfaceName);
        return $this;
    }

    /**
     * Update Data Object with the data from array
     *
     * @param object $dataObject
     * @param array<mixed> $data
     * @param string $interfaceName
     * @return $this
     */
    protected function _setDataValues(object $dataObject, array $data, string $interfaceName): self
    {
        if (empty($data)) {
            return $this;
        }

        $setMethods = $this->getSetters($dataObject);

        foreach (array_intersect_key($data, $setMethods) as $key => $value) {
            $methodName = SimpleDataObjectConverter::snakeCaseToUpperCamelCase($key);

            if (!is_array($value)) {
                if (method_exists($dataObject, 'set' . $methodName)) {
                    $dataObject->{'set' . $methodName}($value);
                } else {
                    $dataObject->{'setIs' . $methodName}($value);
                }
            } else {
                $getterMethodName = 'get' . $methodName;
                $this->setComplexValue($dataObject, $getterMethodName, 'set' . $methodName, $value, $interfaceName);
            }
            unset($data[$key]);
        }

        return $this;
    }

    /**
     * Set complex (like object) value using $methodName based on return type of $getterMethodName
     *
     * @param object $dataObject
     * @param string $getterMethodName
     * @param string $methodName
     * @param array<mixed> $value
     * @param string $interfaceName
     * @return $this
     */
    protected function setComplexValue(
        object $dataObject,
        string $getterMethodName,
        string $methodName,
        array $value,
        string $interfaceName
    ): self {
        if ($interfaceName === '') {
            $interfaceName = get_class($dataObject);
        }

        $returnType = $this->methodsMapProcessor->getMethodReturnType($interfaceName, $getterMethodName);

        if ($this->typeProcessor->isTypeSimple($returnType)) {
            $dataObject->$methodName($value);
            return $this;
        }

        // A getter typed as a plain array or as mixed is a free-form map the spec deliberately leaves
        // open — a payment instrument's `display`, for one. Its value is passed through, because there
        // is no class to build it into.
        $normalizedType = $this->typeProcessor->normalizeType($returnType);

        if (
            $normalizedType === 'array'
            || $normalizedType === TypeProcessor::NORMALIZED_ANY_TYPE
            || str_starts_with((string) $normalizedType, 'array<')
        ) {
            $dataObject->$methodName($value);
            return $this;
        }

        if ($this->typeProcessor->isArrayType($returnType)) {
            $type = $this->typeProcessor->getArrayItemType($returnType);
            $objects = [];
            foreach ($value as $arrayElementData) {
                $object = $this->objectFactory->create($type, []);
                $this->populateWithArray($object, $arrayElementData, $type);
                $objects[] = $object;
            }
            $dataObject->$methodName($objects);
            return $this;
        }

        // For object types, always create empty first, then populate
        $object = $this->objectFactory->create($returnType, []);
        $this->populateWithArray($object, $value, $returnType);
        $dataObject->$methodName($object);

        return $this;
    }

    /**
     * Get list of setters for object
     *
     * @param object $dataObject
     * @return array<string, int>
     */
    private function getSetters(object $dataObject): array
    {
        $class = get_class($dataObject);
        if (!isset($this->settersCache[$class])) {
            $dataObjectMethods = get_class_methods($class);
            // use regexp to manipulate with method list as it use jit starting with PHP 7.3
            $methodsString = implode(',', $dataObjectMethods);
            $processedString = preg_replace(
                ['/(^|,)(?!set)[^,]*/S', '/([A-Z])/S', '/(^|,)set_/iS', '/(^|,)is_([^,]+)/is'],
                ['', '_$1', '$1', '$1$2,is_$2'],
                $methodsString
            );
            $lowercaseString = $processedString !== null ? strtolower($processedString) : '';
            $setters = array_filter(
                explode(',', $lowercaseString)
            );
            $this->settersCache[$class] = array_flip($setters);
        }
        return $this->settersCache[$class];
    }
}
