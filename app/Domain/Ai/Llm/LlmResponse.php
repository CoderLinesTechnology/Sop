<?php

namespace App\Domain\Ai\Llm;

use App\Domain\Ai\Research\UrlNormalizer;

/**
 * A normalised Responses API result: output text, refusal, citations, the
 * web-search activity (queries and the URLs the model actually saw) and token
 * usage.
 */
final class LlmResponse
{
    /**
     * @param  list<array{url:string,title:?string,start_index:?int,end_index:?int}>  $citations
     * @param  list<string>  $searchSources  URLs returned by web_search_call actions
     * @param  list<string>  $searchQueries
     */
    public function __construct(
        public readonly string $id,
        public readonly string $model,
        public readonly string $status,
        public readonly ?string $incompleteReason,
        public readonly string $text,
        public readonly ?string $refusal = null,
        public readonly array $citations = [],
        public readonly array $searchSources = [],
        public readonly array $searchQueries = [],
        public readonly int $searchCalls = 0,
        public readonly int $inputTokens = 0,
        public readonly int $cachedInputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $reasoningTokens = 0,
        public readonly int $durationMs = 0,
        public readonly ?array $error = null,
    ) {}

    public function isComplete(): bool
    {
        return $this->status === 'completed';
    }

    public function isIncomplete(): bool
    {
        return $this->status === 'incomplete';
    }

    /**
     * Every URL the model was shown by the search tool or cited, normalised
     * and de-duplicated. Used by research verification ("seen in search").
     *
     * @return list<string>
     */
    public function seenUrls(): array
    {
        $urls = array_merge($this->searchSources, array_column($this->citations, 'url'));

        return array_values(array_unique(array_filter(array_map(
            fn ($url) => UrlNormalizer::normalize((string) $url),
            $urls,
        ))));
    }

    /**
     * Parse a raw Responses API JSON body.
     *
     * Output items handled: "message" (output_text with url_citation
     * annotations, refusal), "web_search_call" (action search / open_page /
     * find_in_page with queries and sources) and "reasoning" (ignored).
     */
    public static function fromApi(array $json, int $durationMs = 0): self
    {
        $text = '';
        $refusal = null;
        $citations = [];
        $sources = [];
        $queries = [];
        $searchCalls = 0;

        foreach ((array) ($json['output'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            switch ($item['type'] ?? null) {
                case 'message':
                    foreach ((array) ($item['content'] ?? []) as $content) {
                        if (! is_array($content)) {
                            continue;
                        }
                        if (($content['type'] ?? null) === 'output_text') {
                            $text .= (string) ($content['text'] ?? '');
                            foreach ((array) ($content['annotations'] ?? []) as $annotation) {
                                if (is_array($annotation) && ($annotation['type'] ?? null) === 'url_citation' && filled($annotation['url'] ?? null)) {
                                    $citations[] = [
                                        'url' => (string) $annotation['url'],
                                        'title' => isset($annotation['title']) ? (string) $annotation['title'] : null,
                                        'start_index' => isset($annotation['start_index']) ? (int) $annotation['start_index'] : null,
                                        'end_index' => isset($annotation['end_index']) ? (int) $annotation['end_index'] : null,
                                    ];
                                }
                            }
                        } elseif (($content['type'] ?? null) === 'refusal') {
                            $refusal = trim(($refusal ?? '').' '.(string) ($content['refusal'] ?? ''));
                        }
                    }
                    break;

                case 'web_search_call':
                    $searchCalls++;
                    $action = (array) ($item['action'] ?? []);
                    foreach (array_merge((array) ($action['queries'] ?? []), isset($action['query']) ? [$action['query']] : []) as $query) {
                        if (is_string($query) && $query !== '') {
                            $queries[] = $query;
                        }
                    }
                    foreach ((array) ($action['sources'] ?? []) as $source) {
                        $url = is_array($source) ? ($source['url'] ?? null) : (is_string($source) ? $source : null);
                        if (is_string($url) && $url !== '') {
                            $sources[] = $url;
                        }
                    }
                    // open_page / find_in_page actions name the page they read.
                    if (in_array($action['type'] ?? null, ['open_page', 'find_in_page'], true) && is_string($action['url'] ?? null)) {
                        $sources[] = $action['url'];
                    }
                    break;
            }
        }

        if ($text === '' && is_string($json['output_text'] ?? null)) {
            $text = $json['output_text'];
        }

        $usage = (array) ($json['usage'] ?? []);

        return new self(
            id: (string) ($json['id'] ?? ''),
            model: (string) ($json['model'] ?? ''),
            status: (string) ($json['status'] ?? 'completed'),
            incompleteReason: isset($json['incomplete_details']['reason']) ? (string) $json['incomplete_details']['reason'] : null,
            text: $text,
            refusal: $refusal,
            citations: $citations,
            searchSources: array_values(array_unique($sources)),
            searchQueries: array_values(array_unique($queries)),
            searchCalls: $searchCalls,
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            cachedInputTokens: (int) ($usage['input_tokens_details']['cached_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            reasoningTokens: (int) ($usage['output_tokens_details']['reasoning_tokens'] ?? 0),
            durationMs: $durationMs,
            error: is_array($json['error'] ?? null) ? $json['error'] : null,
        );
    }
}
