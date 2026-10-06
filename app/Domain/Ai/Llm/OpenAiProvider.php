<?php

namespace App\Domain\Ai\Llm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * OpenAI Responses API client built on Laravel's HTTP client (no SDK).
 *
 * POST {openai_base_url}/responses with a bearer key and optional
 * OpenAI-Organization / OpenAI-Project headers. Rate limits (429), 5xx and
 * dropped connections are retried in-process a couple of times with
 * exponential backoff (honouring Retry-After); timeouts are not retried here
 * (another full-length wait inside the same worker would risk the stage
 * timeout) but surface as retryable errors so the stage is retried later with
 * backoff. Other 4xx responses are permanent.
 */
class OpenAiProvider implements LlmProvider
{
    /** In-process retries for 429 / 5xx / connection failures. */
    public const HTTP_RETRIES = 2;

    /** Never sleep longer than this inside a worker; longer waits become stage retries. */
    private const MAX_INLINE_WAIT_SECONDS = 30;

    public function name(): string
    {
        return 'openai';
    }

    public function send(LlmRequest $request): LlmResponse
    {
        $apiKey = (string) config('statementra.ai.openai_api_key');
        if ($apiKey === '') {
            throw LlmException::notConfigured('OPENAI_API_KEY is not configured.');
        }

        $payload = $this->payload($request);
        $url = rtrim((string) config('statementra.ai.openai_base_url', 'https://api.openai.com/v1'), '/').'/responses';

        for ($attempt = 1; ; $attempt++) {
            $started = hrtime(true);

            try {
                $response = $this->http($apiKey, $request)->post($url, $payload);
            } catch (ConnectionException $e) {
                if ($this->isTimeout($e)) {
                    throw LlmException::timeout('The model request timed out after '.$this->timeout($request).'s.', $e);
                }
                if ($attempt <= self::HTTP_RETRIES) {
                    $this->wait($attempt, null);

                    continue;
                }
                throw LlmException::connection($e->getMessage(), $e);
            }

            $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

            if ($response->successful()) {
                $json = $response->json();
                if (! is_array($json)) {
                    if ($attempt <= self::HTTP_RETRIES) {
                        $this->wait($attempt, null);

                        continue;
                    }
                    throw LlmException::server($response->status(), 'Malformed (non-JSON) response body.');
                }

                return LlmResponse::fromApi($json, $durationMs);
            }

            $status = $response->status();
            $message = $this->errorMessage($response);
            $errorCode = (string) data_get($response->json(), 'error.code', '');

            // Exhausted quota / billing problems never fix themselves on retry.
            if ($status === 429 && in_array($errorCode, ['insufficient_quota', 'billing_hard_limit_reached'], true)) {
                throw LlmException::client($status, $message, 'insufficient_quota');
            }

            if ($status === 429 || $status === 408 || $status === 409 || $status >= 500) {
                $retryAfter = $this->retryAfter($response);
                if ($attempt <= self::HTTP_RETRIES && ($retryAfter === null || $retryAfter <= self::MAX_INLINE_WAIT_SECONDS)) {
                    $this->wait($attempt, $retryAfter);

                    continue;
                }

                throw $status === 429
                    ? LlmException::rateLimited($retryAfter, 'Rate limited by OpenAI: '.$message)
                    : LlmException::server($status, $message);
            }

            throw LlmException::client($status, $message, in_array($status, [401, 403], true) ? 'auth_error' : 'client_error');
        }
    }

    /**
     * The JSON body sent to /responses. Only documented request fields are
     * used; nulls are dropped but an explicit `store: false` is kept.
     *
     * @return array<string, mixed>
     */
    public function payload(LlmRequest $request): array
    {
        $payload = [
            'model' => $request->model,
            'instructions' => $request->instructions,
            'input' => $request->input,
            'store' => $request->store,
        ];

        if ($request->schema !== null) {
            $payload['text'] = ['format' => [
                'type' => 'json_schema',
                'name' => $request->schema['name'],
                'schema' => $request->schema['schema'],
                'strict' => true,
            ]];
        }

        if ($request->reasoningEffort !== null && $request->reasoningEffort !== '') {
            $payload['reasoning'] = ['effort' => $request->reasoningEffort];
        }

        if ($request->maxOutputTokens !== null) {
            $payload['max_output_tokens'] = $request->maxOutputTokens;
        }

        if ($request->tools !== []) {
            $payload['tools'] = $request->tools;
            if ($request->maxToolCalls !== null) {
                $payload['max_tool_calls'] = $request->maxToolCalls;
            }
        }

        if ($request->include !== []) {
            $payload['include'] = $request->include;
        }

        if ($request->safetyIdentifier !== null) {
            $payload['safety_identifier'] = mb_substr($request->safetyIdentifier, 0, 64);
        }

        if ($request->metadata !== []) {
            $metadata = [];
            foreach (array_slice($request->metadata, 0, 16, true) as $key => $value) {
                $metadata[mb_substr((string) $key, 0, 64)] = mb_substr((string) $value, 0, 512);
            }
            $payload['metadata'] = $metadata;
        }

        return $payload;
    }

    private function http(string $apiKey, LlmRequest $request): PendingRequest
    {
        return Http::withToken($apiKey)
            ->withHeaders(array_filter([
                'OpenAI-Organization' => (string) config('statementra.ai.openai_organization'),
                'OpenAI-Project' => (string) config('statementra.ai.openai_project'),
            ]))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(20)
            ->timeout($this->timeout($request));
    }

    private function timeout(LlmRequest $request): int
    {
        return max(10, $request->timeoutSeconds ?? (int) config('statementra.ai.request_timeout', 300));
    }

    private function isTimeout(ConnectionException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'timed out') || str_contains($message, 'curl error 28') || str_contains($message, 'timeout');
    }

    /** Exponential backoff (1s, 4s, ...) or the server's Retry-After. */
    private function wait(int $attempt, ?int $retryAfter): void
    {
        $seconds = $retryAfter ?? (4 ** ($attempt - 1));

        Sleep::for(min(self::MAX_INLINE_WAIT_SECONDS, max(1, $seconds)))->seconds();
    }

    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');
        if ($header !== '' && is_numeric($header)) {
            return (int) ceil((float) $header);
        }

        $ms = $response->header('retry-after-ms');
        if ($ms !== '' && is_numeric($ms)) {
            return (int) ceil(((float) $ms) / 1000);
        }

        return null;
    }

    private function errorMessage(Response $response): string
    {
        $message = (string) data_get($response->json(), 'error.message', '');
        if ($message === '') {
            $message = mb_substr(trim(strip_tags($response->body())), 0, 300) ?: $response->reason();
        }

        return mb_substr($message, 0, 500);
    }
}
