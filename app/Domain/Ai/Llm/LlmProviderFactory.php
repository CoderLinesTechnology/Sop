<?php

namespace App\Domain\Ai\Llm;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use RuntimeException;

/**
 * Chooses the model provider. An explicit container binding of LlmProvider
 * wins (useful in tests); otherwise the provider recorded on the job, or
 * config('statementra.ai.provider'): "openai" or "fake". The fake provider is
 * refused in production.
 */
class LlmProviderFactory
{
    public function __construct(private readonly Container $app) {}

    public function make(?string $name = null): LlmProvider
    {
        if ($this->app->bound(LlmProvider::class)) {
            return $this->app->make(LlmProvider::class);
        }

        $name = $name ?: self::configuredName();

        return match ($name) {
            'openai' => $this->app->make(OpenAiProvider::class),
            'fake' => $this->fake(),
            default => throw new InvalidArgumentException("Unknown AI provider [{$name}]."),
        };
    }

    public static function configuredName(): string
    {
        return (string) (config('statementra.ai.provider') ?: 'openai');
    }

    private function fake(): FakeProvider
    {
        if ($this->app->environment('production')) {
            throw new RuntimeException('AI_PROVIDER=fake is not allowed in production.');
        }

        return $this->app->make(FakeProvider::class);
    }
}
