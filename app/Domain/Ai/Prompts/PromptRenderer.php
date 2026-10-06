<?php

namespace App\Domain\Ai\Prompts;

/**
 * Renders prompt templates with simple {{variable}} substitution. There is no
 * expression language and no code evaluation: placeholders are replaced in a
 * single pass over the template, so inserted values are never re-scanned
 * (customer text containing "{{...}}" stays literal).
 *
 * Value handling:
 *  - PromptValue::trusted()       inserted verbatim
 *  - int / float / bool / null    inserted as plain scalars
 *  - strings and arrays           wrapped in an <untrusted_data> block
 *                                 (arrays as pretty JSON), the safe default
 */
final class PromptRenderer
{
    /** @var list<string> */
    public array $unknownVariables = [];

    /** @var list<string> sources whose content looked like an injection attempt */
    public array $suspiciousSources = [];

    /** @param array<string, mixed> $variables */
    public function render(string $template, array $variables, string $boundary): string
    {
        $this->unknownVariables = [];
        $this->suspiciousSources = [];

        return preg_replace_callback('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/', function (array $m) use ($variables, $boundary) {
            $name = $m[1];
            if (! array_key_exists($name, $variables)) {
                $this->unknownVariables[] = $name;

                return '';
            }

            return $this->value($name, $variables[$name], $boundary);
        }, $template) ?? $template;
    }

    /** @return list<string> placeholder names used by a template */
    public static function placeholders(string $template): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/', $template, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function value(string $name, mixed $value, string $boundary): string
    {
        if ($value instanceof PromptValue) {
            if ($value->trusted) {
                return (string) $value->value;
            }

            return $this->untrusted($value->value, $value->source ?? $name, $boundary);
        }

        return match (true) {
            $value === null => '(not provided)',
            is_bool($value) => $value ? 'yes' : 'no',
            is_int($value), is_float($value) => (string) $value,
            $value instanceof \BackedEnum => (string) $value->value,
            is_array($value), is_string($value) => $this->untrusted($value, $name, $boundary),
            $value instanceof \Stringable => $this->untrusted((string) $value, $name, $boundary),
            default => '',
        };
    }

    private function untrusted(string|array|null $data, string $source, string $boundary): string
    {
        if ($data === null || $data === '' || $data === []) {
            return '(not provided)';
        }

        $flat = is_array($data) ? (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : $data;
        if (UntrustedData::looksLikeInjection($flat)) {
            $this->suspiciousSources[] = $source;
        }

        return UntrustedData::wrap($data, $source, $boundary);
    }
}
