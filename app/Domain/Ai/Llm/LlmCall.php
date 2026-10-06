<?php

namespace App\Domain\Ai\Llm;

/**
 * What a stage asks the gateway for.
 *
 * `task` is the canonical prompt task (ingestion, analysis, research,
 * verification, strategy, writing, editorial, fact_check, fact_fix,
 * quality_review, refinement, limits, revision); it selects the output
 * schema. The prompt key defaults to the workflow's configured key for the
 * stage (stages.{stage}.prompt_key for the stage's own task, or
 * stages.{stage}.prompt_keys.{task} for secondary tasks), falling back to the
 * task name. Model, effort and output budget default to the stage config.
 */
final class LlmCall
{
    /**
     * @param  array<string, mixed>  $variables  template variables (see PromptRenderer for trust handling)
     * @param  list<array{label:string, part:array<string,mixed>}>  $attachments  input_image / input_file parts
     * @param  array{allowed_domains?:list<string>, search_context_size?:string, country?:?string, max_tool_calls?:int}|null  $webSearch
     * @param  array<string, mixed>  $context  structured inputs for the fake provider (never sent to a real API)
     */
    public function __construct(
        public readonly string $task,
        public readonly array $variables,
        public readonly array $attachments = [],
        public readonly ?array $webSearch = null,
        public readonly ?string $promptKey = null,
        public readonly ?string $model = null,
        public readonly ?string $reasoningEffort = null,
        public readonly ?int $maxOutputTokens = null,
        public readonly array $context = [],
        public readonly string $label = '',
    ) {}
}
