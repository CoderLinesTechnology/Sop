<?php

namespace App\Domain\Ai\Llm;

/**
 * One fully rendered model request (OpenAI Responses API shape).
 *
 * `context` carries structured, already-sanitised inputs for the fake
 * provider so it can synthesise plausible output; it is never sent to a real
 * API.
 */
final class LlmRequest
{
    /**
     * @param  list<array{role:string, content:list<array<string,mixed>>}>  $input
     * @param  array{name:string, schema:array<string,mixed>}|null  $schema
     * @param  list<array<string,mixed>>  $tools
     * @param  list<string>  $include
     * @param  array<string,string>  $metadata
     * @param  array<string,mixed>  $context
     */
    public function __construct(
        public readonly string $model,
        public readonly string $instructions,
        public readonly array $input,
        public readonly ?array $schema = null,
        public readonly ?string $reasoningEffort = null,
        public readonly ?int $maxOutputTokens = null,
        public readonly array $tools = [],
        public readonly ?int $maxToolCalls = null,
        public readonly array $include = [],
        public readonly ?string $safetyIdentifier = null,
        public readonly array $metadata = [],
        public readonly bool $store = false,
        public readonly ?int $timeoutSeconds = null,
        public readonly string $promptKey = '',
        public readonly string $stage = '',
        public readonly array $context = [],
    ) {}

    public function withMaxOutputTokens(int $tokens): self
    {
        return $this->copy(['maxOutputTokens' => $tokens]);
    }

    public function withTimeout(int $seconds): self
    {
        return $this->copy(['timeoutSeconds' => $seconds]);
    }

    /** Append a user message (used for the one-off schema repair re-ask). */
    public function withAppendedUserText(string $text): self
    {
        $input = $this->input;
        $input[] = ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $text]]];

        return $this->copy(['input' => $input]);
    }

    public function usesWebSearch(): bool
    {
        foreach ($this->tools as $tool) {
            if (($tool['type'] ?? null) === 'web_search') {
                return true;
            }
        }

        return false;
    }

    /** All input_text parts joined (handy for tests and the fake provider). */
    public function userText(): string
    {
        $parts = [];
        foreach ($this->input as $message) {
            foreach ($message['content'] ?? [] as $part) {
                if (($part['type'] ?? null) === 'input_text') {
                    $parts[] = (string) $part['text'];
                }
            }
        }

        return implode("\n\n", $parts);
    }

    /** @param array<string, mixed> $changes */
    private function copy(array $changes): self
    {
        $args = get_object_vars($this);

        return new self(...array_replace($args, $changes));
    }
}
