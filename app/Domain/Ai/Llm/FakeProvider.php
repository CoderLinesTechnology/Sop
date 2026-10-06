<?php

namespace App\Domain\Ai\Llm;

use Closure;
use Throwable;

/**
 * Deterministic provider for local development, demos and tests
 * (AI_PROVIDER=fake; refused in production). Without a script it synthesises
 * schema-valid, plausible output from the actual request context (see
 * FakeOutputs), so the whole pipeline runs end to end offline.
 *
 * Tests can script replies per prompt key (or stage):
 *
 *     FakeProvider::queue('research', LlmException::timeout());      // next call fails
 *     FakeProvider::queue('analysis', ['prompt_interpretation' => ...]); // exact JSON
 *     FakeProvider::queue('strategy', '{not json');                   // raw text
 *     FakeProvider::queue('writing', fn (LlmRequest $r, Closure $default) => [...]);
 *     FakeProvider::always('quality_review', fn ($r, $default) => [...]); // every call
 *
 * A reply may be an array (JSON output), a string (raw output text), a
 * Throwable (thrown), an LlmResponse (returned as is) or a Closure receiving
 * the request and a closure producing the default output.
 */
class FakeProvider implements LlmProvider
{
    /** @var array<string, list<mixed>> */
    private static array $queued = [];

    /** @var array<string, mixed> */
    private static array $persistent = [];

    /** @var list<LlmRequest> */
    private static array $calls = [];

    public function __construct(private readonly FakeOutputs $outputs) {}

    public function name(): string
    {
        return 'fake';
    }

    public static function queue(string $key, mixed ...$replies): void
    {
        foreach ($replies as $reply) {
            self::$queued[$key][] = $reply;
        }
    }

    public static function always(string $key, mixed $reply): void
    {
        self::$persistent[$key] = $reply;
    }

    public static function reset(): void
    {
        self::$queued = [];
        self::$persistent = [];
        self::$calls = [];
    }

    /** @return list<LlmRequest> calls made, optionally only those for a task / prompt key */
    public static function calls(?string $key = null): array
    {
        return array_values(array_filter(self::$calls, fn (LlmRequest $r) => $key === null || $r->task === $key || $r->promptKey === $key));
    }

    /** A response cut off by max_output_tokens (for tests). */
    public static function incompleteResponse(string $reason = 'max_output_tokens', string $partial = '{"title": '): LlmResponse
    {
        return LlmResponse::fromApi([
            'id' => 'resp_fake_incomplete',
            'model' => 'fake-model',
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => $reason],
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $partial, 'annotations' => []]]]],
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 4000, 'output_tokens_details' => ['reasoning_tokens' => 0], 'input_tokens_details' => ['cached_tokens' => 0]],
        ]);
    }

    public function send(LlmRequest $request): LlmResponse
    {
        self::$calls[] = $request;

        $reply = $this->nextReply($request);
        $default = fn () => $this->outputs->generate($request);

        if ($reply instanceof Closure) {
            $reply = $reply($request, $default);
        }
        if ($reply instanceof Throwable) {
            throw $reply;
        }
        if ($reply instanceof LlmResponse) {
            return $reply;
        }

        return $this->respond($request, $reply ?? $default());
    }

    private function nextReply(LlmRequest $request): mixed
    {
        $keys = array_values(array_unique(array_filter([$request->task, $request->promptKey, $request->stage])));

        foreach ($keys as $key) {
            if (! empty(self::$queued[$key])) {
                return array_shift(self::$queued[$key]);
            }
        }
        foreach ($keys as $key) {
            if (array_key_exists($key, self::$persistent)) {
                return self::$persistent[$key];
            }
        }

        return null;
    }

    /** Build an API-shaped response and parse it through the real parser. */
    private function respond(LlmRequest $request, array|string $output): LlmResponse
    {
        $text = is_string($output) ? $output : (string) json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $items = [];
        $annotations = [];

        if ($request->usesWebSearch()) {
            preg_match_all('#https?://[^\s"\\\\]+#', $text, $matches);
            $urls = array_values(array_unique($matches[0]));
            $items[] = [
                'type' => 'web_search_call',
                'id' => 'ws_fake_1',
                'status' => 'completed',
                'action' => [
                    'type' => 'search',
                    'queries' => [trim(($request->context['order']['institution'] ?? '').' '.($request->context['order']['programme'] ?? '').' admissions')],
                    'sources' => array_map(fn ($url) => ['type' => 'url', 'url' => $url], $urls),
                ],
            ];
            foreach ($urls as $url) {
                $start = strpos($text, $url);
                $annotations[] = ['type' => 'url_citation', 'url' => $url, 'title' => 'Source', 'start_index' => $start, 'end_index' => $start + strlen($url)];
            }
        }

        $items[] = [
            'type' => 'message',
            'id' => 'msg_fake_1',
            'role' => 'assistant',
            'status' => 'completed',
            'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => $annotations]],
        ];

        $inputChars = strlen($request->instructions) + strlen($request->userText());

        return LlmResponse::fromApi([
            'id' => 'resp_fake_'.substr(hash('sha256', $request->task.$text), 0, 16),
            'model' => $request->model,
            'status' => 'completed',
            'output' => $items,
            'usage' => [
                'input_tokens' => (int) ceil($inputChars / 4),
                'input_tokens_details' => ['cached_tokens' => 0],
                'output_tokens' => (int) ceil(strlen($text) / 4),
                'output_tokens_details' => ['reasoning_tokens' => 0],
            ],
        ], 5);
    }
}
