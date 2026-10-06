<?php

namespace App\Domain\Ai\Llm;

/**
 * What a stage asks the gateway for: a prompt key, its template variables,
 * the output schema and optional attachments / web search. Model, effort and
 * output budget default to the stage's workflow configuration.
 */
final class LlmCall
{
    /**
     * @param  array<string, mixed>  $variables  template variables (see PromptRenderer for trust handling)
     * @param  array{name:string, schema:array}  $schema
     * @param  list<array{label:string, part:array<string,mixed>}>  $attachments  input_image / input_file parts
     * @param  array{allowed_domains?:list<string>, search_context_size?:string, country?:?string, max_tool_calls?:int}|null  $webSearch
     * @param  array<string, mixed>  $context  structured inputs for the fake provider (never sent to a real API)
     */
    public function __construct(
        public readonly string $promptKey,
        public readonly array $variables,
        public readonly array $schema,
        public readonly array $attachments = [],
        public readonly ?array $webSearch = null,
        public readonly ?string $model = null,
        public readonly ?string $reasoningEffort = null,
        public readonly ?int $maxOutputTokens = null,
        public readonly array $context = [],
        public readonly string $label = '',
    ) {}
}
