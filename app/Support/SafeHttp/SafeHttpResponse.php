<?php

namespace App\Support\SafeHttp;

final class SafeHttpResponse
{
    public function __construct(
        public readonly string $url,
        public readonly int $status,
        public readonly string $contentType,
        public readonly string $body,
        public readonly bool $truncated,
    ) {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** Visible text of an HTML page (scripts, styles and tags removed, whitespace collapsed). */
    public function text(): string
    {
        if ($this->contentType === 'text/plain') {
            return trim(preg_replace('/\s+/u', ' ', $this->body) ?? '');
        }

        $html = preg_replace('#<(script|style|noscript|svg|template)\b[^>]*>.*?</\1>#is', ' ', $this->body) ?? '';
        $html = preg_replace('#<br\s*/?>|</(p|div|li|h[1-6]|tr|section|article)>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
