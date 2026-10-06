<?php

namespace App\Domain\Ai\Prompts;

use App\Models\PromptVersion;

final class ResolvedPrompt
{
    public function __construct(
        public readonly string $key,
        public readonly string $systemPrompt,
        public readonly string $userTemplate,
        public readonly ?PromptVersion $version,
    ) {}

    public function versionLabel(): string
    {
        return $this->version ? 'v'.$this->version->version : 'builtin';
    }
}
