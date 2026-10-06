<?php

namespace App\Domain\Ai\Research;

/**
 * Canonical form of research URLs so "seen in search results" comparisons and
 * the research_sources unique key are stable: lower-case scheme and host, no
 * fragment, no default port, no trailing slash, tracking parameters removed.
 */
final class UrlNormalizer
{
    private const TRACKING_PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'mc_cid', 'mc_eid', 'ref', 'srsltid'];

    public static function normalize(string $url): ?string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower(rtrim($parts['host'], '.'));
        $port = isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $path = $path === '/' ? '' : rtrim($path, '/');

        $query = '';
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $params);
            $params = array_diff_key($params, array_flip(self::TRACKING_PARAMS));
            ksort($params);
            $query = $params ? '?'.http_build_query($params) : '';
        }

        return "{$scheme}://{$host}{$port}{$path}{$query}";
    }

    public static function host(string $url): ?string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower(rtrim($host, '.')) : null;
    }

    /** Registrable-ish domain without "www." (used for display and grouping). */
    public static function domain(string $url): ?string
    {
        $host = self::host($url);

        return $host === null ? null : (str_starts_with($host, 'www.') ? substr($host, 4) : $host);
    }

    /** Bare domain for web_search allowed_domains (no scheme, path or www). */
    public static function bareDomain(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }
        if (! str_contains($value, '://')) {
            $value = 'https://'.$value;
        }

        $host = self::host($value);
        if ($host === null || ! str_contains($host, '.') || ! preg_match('/^[a-z0-9.-]+$/', $host)) {
            return null;
        }

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** Whether $host equals $domain or is a subdomain of it. */
    public static function hostMatches(string $host, string $domain): bool
    {
        $host = strtolower($host);
        $domain = strtolower(ltrim($domain, '.'));

        return $host === $domain || str_ends_with($host, '.'.$domain);
    }

    public static function hash(string $normalizedUrl): string
    {
        return hash('sha256', $normalizedUrl);
    }
}
