<?php

namespace App\Domain\Ai\Llm;

use App\Models\PromptVersion;

/** Schema-validated output of a gateway call, with the raw response metadata. */
final class LlmResult
{
    public function __construct(
        public readonly array $data,
        public readonly LlmResponse $response,
        public readonly ?PromptVersion $promptVersion,
        public readonly string $model,
    ) {}
}
