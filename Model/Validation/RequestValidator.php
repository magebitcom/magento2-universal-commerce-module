<?php

/**
 * @author Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license https://magebit.com/code-license
 */
declare(strict_types=1);

namespace Magebit\UniversalCommerce\Model\Validation;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Validates raw array data against interface method signatures using Reflection API
 */
class RequestValidator
{
    /**
     * Validate array data against interface/class structure
     *
     * @param array<mixed> $data Raw array data to validate
     * @param string $className Fully qualified class or interface name
     * @return ValidationResult
     */
    public function validate(array $data, string $className): ValidationResult
    {
        if (!class_exists($className) && !interface_exists($className)) {
            return new ValidationResult(['' => sprintf('Class or interface "%s" does not exist', $className)]);
        }

        $reflection = new ReflectionClass($className);
        $errors = $this->validateClass($data, $reflection, '');

        return new ValidationResult($errors);
    }

    /**
     * Validate data against a reflection class
     *
     * @param array<mixed> $data
     * @param ReflectionClass<object> $reflection
     * @param string $pathPrefix Dot-notation path prefix for nested errors
     * @return array<string, string>
     */
    private function validateClass(array $data, ReflectionClass $reflection, string $pathPrefix): array
    {
        $errors = [];
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            $methodName = $method->getName();

            // Only process getter methods, skip common base methods
            if (!str_starts_with($methodName, 'get') || $this->shouldSkipMethod($methodName)) {
                continue;
            }

            $key = $this->methodToKey($methodName);
            $fullPath = $pathPrefix ? "$pathPrefix.$key" : $key;
            $returnType = $method->getReturnType();

            // Check if field is required (non-nullable)
            $isRequired = $returnType !== null && !$returnType->allowsNull();

            // Check if field exists in data
            if (!array_key_exists($key, $data)) {
                if ($isRequired) {
                    $errors[$fullPath] = sprintf('Required field "%s" is missing', $key);
                }
                continue;
            }

            $value = $data[$key];

            // Validate null values
            if ($value === null) {
                if ($isRequired) {
                    $errors[$fullPath] = sprintf('Field "%s" cannot be null', $key);
                }
                continue;
            }

            // Validate based on return type
            if ($returnType instanceof ReflectionNamedType) {
                $typeErrors = $this->validateType($value, $returnType, $method, $fullPath);
                $errors = array_merge($errors, $typeErrors);
            }
        }

        return $errors;
    }

    /**
     * Validate value against return type
     *
     * @param mixed $value
     * @param ReflectionNamedType $returnType
     * @param ReflectionMethod $method
     * @param string $pathPrefix
     * @return array<string, string>
     */
    private function validateType(
        mixed $value,
        ReflectionNamedType $returnType,
        ReflectionMethod $method,
        string $pathPrefix
    ): array {
        $typeName = $returnType->getName();
        $errors = [];

        // Handle array types
        if ($typeName === 'array') {
            if (!is_array($value)) {
                return [$pathPrefix => sprintf('Field "%s" must be an array', basename($pathPrefix))];
            }

            // Try to extract array element type from PHPDoc
            $elementType = $this->extractArrayElementType($method);
            if ($elementType !== null) {
                foreach ($value as $index => $item) {
                    $itemPath = "$pathPrefix.$index";
                    if (!is_array($item)) {
                        $errors[$itemPath] = sprintf('Array item at index %d must be an array', $index);
                        continue;
                    }

                    $itemErrors = $this->validateClass($item, new ReflectionClass($elementType), $itemPath);
                    $errors = array_merge($errors, $itemErrors);
                }
            }
            return $errors;
        }

        // Handle scalar types
        if ($this->isScalarType($typeName)) {
            if (!$this->validateScalarType($value, $typeName)) {
                $errors[$pathPrefix] = sprintf(
                    'Field "%s" must be of type %s, got %s',
                    basename($pathPrefix),
                    $typeName,
                    gettype($value)
                );

                return $errors;
            }

            $allowed = is_string($value) ? $this->allowedValues($method) : [];

            if ($allowed !== [] && !in_array($value, $allowed, true)) {
                $errors[$pathPrefix] = sprintf(
                    'Field "%s" must be one of: %s',
                    basename($pathPrefix),
                    implode(', ', $allowed)
                );
            }

            return $errors;
        }

        // Handle interface/class types (nested objects)
        if (class_exists($typeName) || interface_exists($typeName)) {
            if (!is_array($value)) {
                return [$pathPrefix => sprintf('Field "%s" must be an array for nested object', basename($pathPrefix))];
            }

            $nestedErrors = $this->validateClass($value, new ReflectionClass($typeName), $pathPrefix);
            return $nestedErrors;
        }

        return $errors;
    }

    /**
     * Extract array element type from PHPDoc comment
     *
     * @param ReflectionMethod $method
     * @return string|null Fully qualified class/interface name or null
     */
    private function extractArrayElementType(ReflectionMethod $method): ?string
    {
        $docComment = $method->getDocComment();
        if ($docComment === false) {
            return null;
        }

        // Match @return SomeInterface[] or @return \Namespace\SomeInterface[]
        if (preg_match('/@return\s+([^\s\[\]]+)\[\]/', $docComment, $matches)) {
            $typeName = trim($matches[1]);
            return $this->resolveTypeName($typeName, $method->getDeclaringClass());
        }

        return null;
    }

    /**
     * Resolve type name to fully qualified class/interface name
     *
     * @param string $typeName Type name from PHPDoc (may be short or fully qualified)
     * @param ReflectionClass<object> $declaringClass Class where the type is used
     * @return string|null Fully qualified class/interface name or null if not found
     */
    private function resolveTypeName(string $typeName, ReflectionClass $declaringClass): ?string
    {
        // Remove leading backslash if present
        $typeName = ltrim($typeName, '\\');

        // If already fully qualified, check if it exists
        if (str_contains($typeName, '\\')) {
            if (class_exists($typeName) || interface_exists($typeName)) {
                return $typeName;
            }
            return null;
        }

        // Try to resolve from use statements
        $resolvedType = $this->resolveFromUseStatements($typeName, $declaringClass);
        if ($resolvedType !== null) {
            return $resolvedType;
        }

        // Try relative to declaring class namespace
        $namespace = $declaringClass->getNamespaceName();
        if ($namespace !== '') {
            $fullName = $namespace . '\\' . $typeName;
            if (class_exists($fullName) || interface_exists($fullName)) {
                return $fullName;
            }
        }

        return null;
    }

    /**
     * Resolve type name from use statements in the file
     *
     * @param string $typeName Short type name
     * @param ReflectionClass<object> $declaringClass
     * @return string|null Fully qualified name or null
     */
    private function resolveFromUseStatements(string $typeName, ReflectionClass $declaringClass): ?string
    {
        $fileName = $declaringClass->getFileName();
        if ($fileName === false || !file_exists($fileName)) {
            return null;
        }

        $fileContent = file_get_contents($fileName);
        if ($fileContent === false) {
            return null;
        }

        // Extract use statements
        // Match: use Fully\Qualified\ClassName; or use Fully\Qualified\ClassName as Alias;
        if (preg_match_all('/^use\s+([^\s;]+)(?:\s+as\s+(\w+))?;/m', $fileContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fullName = trim($match[1]);
                $alias = $match[2] ?? null;

                // Check if the type name matches the alias or the last part of the full name
                $lastPart = substr($fullName, strrpos($fullName, '\\') + 1);
                if ($typeName === $alias || $typeName === $lastPart) {
                    if (class_exists($fullName) || interface_exists($fullName)) {
                        return $fullName;
                    }
                }
            }
        }

        return null;
    }

    /**
     * The generated interfaces carry one constant per allowed value, named after the field, and only
     * where the specification actually limits it. A field with no such constants is free-form.
     *
     * @param ReflectionMethod $method
     * @return string[]
     */
    private function allowedValues(ReflectionMethod $method): array
    {
        $prefix = strtoupper($this->methodToKey($method->getName())) . '_';
        $allowed = [];

        foreach ($method->getDeclaringClass()->getConstants() as $name => $constant) {
            if (str_starts_with($name, $prefix) && is_string($constant)) {
                $allowed[] = $constant;
            }
        }

        return $allowed;
    }

    /**
     * Convert method name to snake_case key
     *
     * @param string $methodName e.g., "getFooBar"
     * @return string e.g., "foo_bar"
     */
    private function methodToKey(string $methodName): string
    {
        $name = substr($methodName, 3); // Remove 'get' prefix
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    /**
     * Check if method should be skipped
     *
     * @param string $methodName
     * @return bool
     */
    private function shouldSkipMethod(string $methodName): bool
    {
        $skipMethods = ['getData', 'getIterator', 'getIteratorAggregate'];
        return in_array($methodName, $skipMethods, true);
    }

    /**
     * Check if type is scalar
     *
     * @param string $typeName
     * @return bool
     */
    private function isScalarType(string $typeName): bool
    {
        return in_array($typeName, ['string', 'int', 'float', 'bool'], true);
    }

    /**
     * Validate scalar type
     *
     * @param mixed $value
     * @param string $typeName
     * @return bool
     */
    private function validateScalarType(mixed $value, string $typeName): bool
    {
        return match ($typeName) {
            'string' => is_string($value),
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value), // Allow int for float
            'bool' => is_bool($value),
            default => false,
        };
    }
}
