<?php

/**
 * This file is part of the Magebit_UniversalCommerce package.
 *
 * @copyright Copyright (c) 2026 Magebit, Ltd. (https://magebit.com/)
 * @author    Magebit <info@magebit.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Magebit\UniversalCommerce\Test\Unit;

use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\JsonPointer;
use Opis\JsonSchema\Validator;

/**
 * Validates payloads against the vendored UCP JSON Schema (draft 2020-12) set.
 * PHPUnit glue lives in the SchemaAssert trait.
 */
class SpecSchemaValidator
{
    /**
     * Absolute $id prefix the spec schemas use, mapped onto the local schema directory.
     */
    public const SCHEMA_BASE_URI = 'https://ucp.dev/schemas/';

    /**
     * Location of the vendored spec, relative to the project root.
     */
    public const SCHEMA_DIR = 'libraries/ucp-php-spec/spec/schemas';

    /**
     * A class from the installed specification package, used to find where that package lives.
     */
    public const RUNTIME_CLASS = 'Magebit\UcpSpec\Runtime\SpecObject';

    /**
     * Where the schemas sit inside that package.
     */
    public const PACKAGE_SCHEMA_DIR = 'spec/schemas';

    /**
     * Collect a whole batch of divergences per run instead of only the first.
     */
    public const MAX_ERRORS = 50;

    /**
     * The schemas ship inside the installed specification package, so they are found through it
     * rather than through a path. Walking up for a directory only this checkout has meant the
     * schema tests skipped themselves everywhere else, reporting green while covering nothing.
     *
     * @return string|null
     */
    public static function locateSchemaDir(): ?string
    {
        $installed = self::installedSchemaDir();

        if ($installed !== null) {
            return $installed;
        }

        // Falls back to the working copy, for anyone editing the specification library in place.
        $dir = __DIR__;

        while (true) {
            $candidate = $dir . '/' . self::SCHEMA_DIR;

            if (is_dir($candidate)) {
                return $candidate;
            }

            $parent = dirname($dir);

            if ($parent === $dir) {
                return null;
            }

            $dir = $parent;
        }
    }

    /**
     * @return string|null
     */
    private static function installedSchemaDir(): ?string
    {
        if (!class_exists(self::RUNTIME_CLASS)) {
            return null;
        }

        try {
            $file = (new \ReflectionClass(self::RUNTIME_CLASS))->getFileName();
        } catch (\ReflectionException $exception) {
            return null;
        }

        if ($file === false) {
            return null;
        }

        $candidate = dirname($file, 2) . '/' . self::PACKAGE_SCHEMA_DIR;

        return is_dir($candidate) ? $candidate : null;
    }

    /**
     * Splits "file.json#/pointer" and returns the absolute file path, or null when absent.
     *
     * @param string $schemaDir
     * @param string $schemaPath
     * @return string|null
     */
    public static function resolveFile(string $schemaDir, string $schemaPath): ?string
    {
        $relativeFile = explode('#', ltrim($schemaPath, '/'), 2)[0];
        $file = $schemaDir . '/' . $relativeFile;

        return is_file($file) ? $file : null;
    }

    /**
     * Returns null when the payload is valid, otherwise a readable failure description.
     *
     * @param array<mixed>|object $payload
     * @param string $schemaDir
     * @param string $schemaPath
     * @return string|null
     * @throws JsonException
     */
    public static function validate(array|object $payload, string $schemaDir, string $schemaPath): ?string
    {
        [$relativeFile, $fragment] = array_pad(explode('#', ltrim($schemaPath, '/'), 2), 2, null);

        // Several spec files declare an $id that differs from their filename, so a subschema is
        // addressed by $ref through the registered prefix while a whole file is loaded directly.
        $schema = $fragment === null
            ? json_decode((string) file_get_contents($schemaDir . '/' . $relativeFile), false, 512, JSON_THROW_ON_ERROR)
            : (object) ['$ref' => self::SCHEMA_BASE_URI . $relativeFile . '#' . $fragment];

        $result = self::createValidator($schemaDir)->validate(self::toJsonData($payload), $schema);

        if ($result->isValid()) {
            return null;
        }

        return self::describeError($schemaPath, $result->error());
    }

    /**
     * Round-trips through JSON so associative arrays become objects. An empty PHP array
     * deliberately stays `[]` rather than `{}` — that mismatch is a real defect worth surfacing.
     *
     * @param array<mixed>|object $payload
     * @return mixed
     * @throws JsonException
     */
    private static function toJsonData(array|object $payload): mixed
    {
        return json_decode(json_encode($payload, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Builds a validator per assertion; a shared one would collide on repeated root $id registration.
     *
     * @param string $schemaDir
     * @return Validator
     */
    private static function createValidator(string $schemaDir): Validator
    {
        $validator = new Validator(null, self::MAX_ERRORS, false);
        $validator->resolver()?->registerPrefix(self::SCHEMA_BASE_URI, $schemaDir);

        return $validator;
    }

    /**
     * @param string $schemaPath
     * @param ValidationError|null $error
     * @return string
     */
    private static function describeError(string $schemaPath, ?ValidationError $error): string
    {
        if ($error === null) {
            return sprintf('Payload does not match "%s", but no error detail was reported.', $schemaPath);
        }

        $lines = [];
        self::collectLeafErrors($error, new ErrorFormatter(), $lines);

        return sprintf("Payload does not match \"%s\":\n%s", $schemaPath, implode("\n", $lines));
    }

    /**
     * Keeps only leaf errors — parent $ref/allOf frames repeat the same failure without adding detail.
     *
     * @param ValidationError $error
     * @param ErrorFormatter $formatter
     * @param array<int, string> $lines
     * @return void
     */
    private static function collectLeafErrors(ValidationError $error, ErrorFormatter $formatter, array &$lines): void
    {
        $subErrors = $error->subErrors();

        if ($subErrors !== []) {
            foreach ($subErrors as $subError) {
                self::collectLeafErrors($subError, $formatter, $lines);
            }

            return;
        }

        $schemaPointer = $error->schema()->info()->path();
        $schemaPointer[] = $error->keyword();

        $lines[] = sprintf(
            "  - [%s] data: %s | schema: %s\n      %s",
            $error->keyword(),
            JsonPointer::pathToFragment($error->data()->fullPath()),
            JsonPointer::pathToFragment($schemaPointer),
            $formatter->formatErrorMessage($error)
        );
    }
}
