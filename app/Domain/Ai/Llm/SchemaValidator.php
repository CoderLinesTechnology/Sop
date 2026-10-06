<?php

namespace App\Domain\Ai\Llm;

/**
 * Validates decoded model output against the strict JSON-schema subset used
 * by our structured-output schemas: type (incl. nullable unions), properties,
 * required, additionalProperties:false, items, enum, minimum/maximum,
 * minItems/maxItems. Returns human-readable errors (used in the repair
 * re-ask); an empty list means valid.
 */
final class SchemaValidator
{
    private const MAX_ERRORS = 25;

    /** @return list<string> */
    public function validate(mixed $data, array $schema): array
    {
        $errors = [];
        $this->check($data, $schema, '$', $errors);

        return array_slice($errors, 0, self::MAX_ERRORS);
    }

    /**
     * Strict-mode lint for our own schemas: every object must list all its
     * properties as required and forbid additional properties.
     *
     * @return list<string>
     */
    public static function strictModeProblems(array $schema, string $path = '$'): array
    {
        $problems = [];
        $types = (array) ($schema['type'] ?? []);

        if (in_array('object', $types, true)) {
            $properties = array_keys((array) ($schema['properties'] ?? []));
            if (($schema['additionalProperties'] ?? null) !== false) {
                $problems[] = "{$path}: additionalProperties must be false";
            }
            $required = (array) ($schema['required'] ?? []);
            sort($required);
            $sorted = $properties;
            sort($sorted);
            if ($required !== $sorted) {
                $problems[] = "{$path}: every property must be required";
            }
            foreach ((array) ($schema['properties'] ?? []) as $name => $child) {
                $problems = [...$problems, ...self::strictModeProblems($child, "{$path}.{$name}")];
            }
        }

        if (in_array('array', $types, true) && isset($schema['items'])) {
            $problems = [...$problems, ...self::strictModeProblems($schema['items'], "{$path}[]")];
        }

        return $problems;
    }

    /** @param list<string> $errors */
    private function check(mixed $value, array $schema, string $path, array &$errors): void
    {
        if (count($errors) >= self::MAX_ERRORS) {
            return;
        }

        $types = (array) ($schema['type'] ?? []);
        if ($types !== [] && ! $this->matchesAnyType($value, $types)) {
            $errors[] = "{$path}: expected ".implode('|', $types).', got '.$this->describe($value);

            return;
        }

        if (isset($schema['enum']) && ! in_array($value, (array) $schema['enum'], true)) {
            $errors[] = "{$path}: value must be one of ".implode(', ', array_map(fn ($v) => json_encode($v), (array) $schema['enum']));

            return;
        }

        if ($value === null) {
            return;
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "{$path}: must be >= {$schema['minimum']}";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "{$path}: must be <= {$schema['maximum']}";
            }
        }

        if (is_array($value) && array_is_list($value) && in_array('array', $types, true)) {
            if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
                $errors[] = "{$path}: needs at least {$schema['minItems']} item(s)";
            }
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $errors[] = "{$path}: allows at most {$schema['maxItems']} item(s)";
            }
            if (isset($schema['items'])) {
                foreach ($value as $i => $item) {
                    $this->check($item, $schema['items'], "{$path}[{$i}]", $errors);
                }
            }

            return;
        }

        if (is_array($value) && in_array('object', $types, true)) {
            $properties = (array) ($schema['properties'] ?? []);
            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (! array_key_exists($required, $value)) {
                    $errors[] = "{$path}: missing required property \"{$required}\"";
                }
            }
            if (($schema['additionalProperties'] ?? true) === false) {
                foreach (array_keys($value) as $key) {
                    if (! array_key_exists($key, $properties)) {
                        $errors[] = "{$path}: unexpected property \"{$key}\"";
                    }
                }
            }
            foreach ($properties as $name => $child) {
                if (array_key_exists($name, $value)) {
                    $this->check($value[$name], $child, "{$path}.{$name}", $errors);
                }
            }
        }
    }

    /** @param list<string> $types */
    private function matchesAnyType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                'null' => $value === null,
                'string' => is_string($value),
                'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
                default => false,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    private function describe(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) && array_is_list($value) => 'array',
            is_array($value) => 'object',
            default => get_debug_type($value),
        };
    }
}
