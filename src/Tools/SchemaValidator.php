<?php

declare(strict_types=1);

namespace MailboxMcp\Tools;

/**
 * Validates tool arguments against a JSON-schema subset before any mail
 * command runs. Supports: `type` (object, string, integer, boolean,
 * array), `properties`, `required`, `additionalProperties: false`, `enum`,
 * `minimum`, `maximum`, `minLength`, `maxLength`, `pattern`, `items`,
 * `minItems`, `maxItems`, and `default` (via {@see applyDefaults()}).
 * Unknown keywords are ignored. Collects every error found rather than
 * stopping at the first.
 */
final class SchemaValidator
{
    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    public function validate(array $schema, array $data): array
    {
        $errors = [];
        $this->validateNode($schema, $data, '', $errors);

        return $errors;
    }

    /**
     * Fills missing top-level properties that declare a `default`. Nested
     * defaults are not applied.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function applyDefaults(array $schema, array $data): array
    {
        if (!array_key_exists('properties', $schema) || !is_array($schema['properties'])) {
            return $data;
        }

        foreach ($schema['properties'] as $name => $propertySchema) {
            if (!is_string($name) || !is_array($propertySchema)) {
                continue;
            }

            if (!array_key_exists($name, $data) && array_key_exists('default', $propertySchema)) {
                $data[$name] = $propertySchema['default'];
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $errors
     */
    private function validateNode(array $schema, mixed $data, string $path, array &$errors): void
    {
        if (array_key_exists('type', $schema) && is_string($schema['type'])) {
            if (!$this->matchesType($schema['type'], $data)) {
                $errors[] = $this->label($path) . ': must be ' . $this->typeLabel($schema['type']);

                return;
            }
        }

        if (array_key_exists('enum', $schema) && is_array($schema['enum']) && !in_array($data, $schema['enum'], true)) {
            $allowed = array_map(
                static fn (mixed $v): string => is_string($v) ? $v : (string) json_encode($v),
                $schema['enum'],
            );
            $errors[] = $this->label($path) . ': must be one of ' . implode(', ', $allowed);
        }

        if (is_int($data)) {
            if (array_key_exists('minimum', $schema) && is_int($schema['minimum']) && $data < $schema['minimum']) {
                $errors[] = $this->label($path) . ': must be >= ' . $schema['minimum'];
            }

            if (array_key_exists('maximum', $schema) && is_int($schema['maximum']) && $data > $schema['maximum']) {
                $errors[] = $this->label($path) . ': must be <= ' . $schema['maximum'];
            }
        }

        if (is_string($data)) {
            $length = mb_strlen($data);

            if (array_key_exists('minLength', $schema) && is_int($schema['minLength']) && $length < $schema['minLength']) {
                $errors[] = $this->label($path) . ': must be at least ' . $schema['minLength'] . ' characters';
            }

            if (array_key_exists('maxLength', $schema) && is_int($schema['maxLength']) && $length > $schema['maxLength']) {
                $errors[] = $this->label($path) . ': must be at most ' . $schema['maxLength'] . ' characters';
            }

            if (array_key_exists('pattern', $schema) && is_string($schema['pattern'])) {
                $matches = $this->matchesPattern($schema['pattern'], $data);

                if (null === $matches) {
                    $errors[] = $this->label($path) . ': invalid pattern';
                } elseif (!$matches) {
                    $errors[] = $this->label($path) . ': must match pattern ' . $schema['pattern'];
                }
            }
        }

        if (is_array($data)) {
            $this->validateArrayKeywords($schema, $data, $path, $errors);
            $this->validateObjectKeywords($schema, $data, $path, $errors);
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<array-key, mixed> $data
     * @param list<string>         $errors
     */
    private function validateArrayKeywords(array $schema, array $data, string $path, array &$errors): void
    {
        $isList = array_is_list($data);

        if ($isList && array_key_exists('minItems', $schema) && is_int($schema['minItems']) && count($data) < $schema['minItems']) {
            $errors[] = $this->label($path) . ': must have at least ' . $schema['minItems'] . ' items';
        }

        if ($isList && array_key_exists('maxItems', $schema) && is_int($schema['maxItems']) && count($data) > $schema['maxItems']) {
            $errors[] = $this->label($path) . ': must have at most ' . $schema['maxItems'] . ' items';
        }

        if ($isList && array_key_exists('items', $schema) && is_array($schema['items'])) {
            /** @var array<string, mixed> $itemSchema */
            $itemSchema = $schema['items'];

            foreach ($data as $index => $item) {
                $this->validateNode($itemSchema, $item, $this->childPath($path, (string) $index), $errors);
            }
        }
    }

    /**
     * @param array<string, mixed>    $schema
     * @param array<array-key, mixed> $data
     * @param list<string>            $errors
     */
    private function validateObjectKeywords(array $schema, array $data, string $path, array &$errors): void
    {
        // `required` and `additionalProperties` are independent of
        // `properties` being declared at all, so they are checked
        // regardless of whether the schema has a `properties` keyword.
        if (array_key_exists('required', $schema) && is_array($schema['required'])) {
            foreach ($schema['required'] as $requiredName) {
                if (is_string($requiredName) && !array_key_exists($requiredName, $data)) {
                    $errors[] = $this->label($this->childPath($path, $requiredName)) . ': required';
                }
            }
        }

        /** @var array<array-key, mixed> $properties */
        $properties = [];

        if (array_key_exists('properties', $schema) && is_array($schema['properties'])) {
            /** @var array<array-key, mixed> $properties */
            $properties = $schema['properties'];

            foreach ($properties as $name => $propertySchema) {
                if (!is_string($name) || !is_array($propertySchema) || !array_key_exists($name, $data)) {
                    continue;
                }

                /** @var array<string, mixed> $propertySchema */
                $this->validateNode($propertySchema, $data[$name], $this->childPath($path, $name), $errors);
            }
        }

        if (array_key_exists('additionalProperties', $schema) && false === $schema['additionalProperties']) {
            foreach (array_keys($data) as $key) {
                $keyName = (string) $key;

                if (!array_key_exists($keyName, $properties)) {
                    $errors[] = $this->label($this->childPath($path, $keyName)) . ': unexpected property';
                }
            }
        }
    }

    /**
     * Compiles the schema-authored (unanchored-delimiter, unescaped)
     * `$pattern` into a `/..../u` PCRE, escaping any unescaped `/` first so
     * a literal slash in the pattern does not end the delimiter early, and
     * matches it against `$value`. Returns null, instead of raising a PHP
     * warning, when the pattern itself fails to compile.
     */
    private function matchesPattern(string $pattern, string $value): ?bool
    {
        $escaped = preg_replace('~(?<!\\\\)/~', '\\/', $pattern);
        $regex = '/' . (null === $escaped ? $pattern : $escaped) . '/u';

        $result = @preg_match($regex, $value);

        return false === $result ? null : 1 === $result;
    }

    private function matchesType(string $type, mixed $data): bool
    {
        return match ($type) {
            'object' => is_array($data) && ([] === $data || !array_is_list($data)),
            'string' => is_string($data),
            'integer' => is_int($data),
            'boolean' => is_bool($data),
            'array' => is_array($data) && array_is_list($data),
            default => true,
        };
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'object' => 'an object',
            'string' => 'a string',
            'integer' => 'an integer',
            'boolean' => 'a boolean',
            'array' => 'an array',
            default => $type,
        };
    }

    private function label(string $path): string
    {
        return '' === $path ? '$' : $path;
    }

    private function childPath(string $parent, string $name): string
    {
        return '' === $parent ? $name : $parent . '.' . $name;
    }
}
