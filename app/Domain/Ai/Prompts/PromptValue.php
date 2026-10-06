<?php

namespace App\Domain\Ai\Prompts;

/**
 * A template variable with explicit trust. Strings and arrays passed to the
 * renderer without this wrapper are treated as untrusted by default; only
 * text we (or administrators) control may be marked trusted.
 */
final class PromptValue
{
    private function __construct(
        public readonly string|array|null $value,
        public readonly bool $trusted,
        public readonly ?string $source = null,
    ) {}

    /** Text controlled by Statementra or administrators (e.g. service writing guidance). */
    public static function trusted(string $text): self
    {
        return new self($text, true);
    }

    /** Customer, document, web or model-derived content. */
    public static function untrusted(string|array|null $data, ?string $source = null): self
    {
        return new self($data, false, $source);
    }
}
