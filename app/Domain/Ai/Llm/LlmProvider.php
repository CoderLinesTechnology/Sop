<?php

namespace App\Domain\Ai\Llm;

/**
 * A language-model backend. Implementations translate an LlmRequest into one
 * API call and normalise the result; they do not know about orders, budgets
 * or prompts (that is the LlmGateway's job).
 */
interface LlmProvider
{
    /** Short provider name stored on ai_jobs / ai_usages ("openai", "fake"). */
    public function name(): string;

    /**
     * Send one request. Returns the response for any HTTP-successful call
     * (including status "incomplete"); throws LlmException for transport,
     * HTTP and provider errors.
     *
     * @throws LlmException
     */
    public function send(LlmRequest $request): LlmResponse;
}
