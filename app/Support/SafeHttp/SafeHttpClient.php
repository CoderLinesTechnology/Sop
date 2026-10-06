<?php

namespace App\Support\SafeHttp;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * SSRF-hardened fetcher for public web pages (used to verify research claims
 * against their sources).
 *
 *  - HTTPS only, standard port only, no credentials in URLs.
 *  - Host is resolved once; every resolved address must be public (private,
 *    loopback, link-local/cloud-metadata, CGNAT and reserved ranges are
 *    refused) and the connection is pinned to that address (no DNS rebinding).
 *  - Redirects are followed manually and each hop is re-validated.
 *  - Strict connect/total timeouts and a response size cap.
 */
class SafeHttpClient
{
    public const MAX_BYTES = 2_000_000;

    private const MAX_REDIRECTS = 3;

    private const ALLOWED_TYPES = ['text/html', 'application/xhtml+xml', 'text/plain', 'application/pdf'];

    /** @var callable|null test hook: fn(string $host): list<string> */
    public static $resolver = null;

    /** @throws SafeHttpException */
    public function get(string $url): SafeHttpResponse
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$host, $ip] = $this->validate($current);
            $response = $this->request($current, $host, $ip);
            $status = $response->getStatusCode();

            if ($status >= 300 && $status < 400 && $response->hasHeader('Location')) {
                $current = $this->absolute($current, $response->getHeaderLine('Location'));

                continue;
            }

            $type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0] ?? ''));
            if ($status < 400 && $type !== '' && ! in_array($type, self::ALLOWED_TYPES, true)) {
                throw new SafeHttpException("Unsupported content type [{$type}].");
            }

            $body = (string) $response->getBody();
            $truncated = strlen($body) >= self::MAX_BYTES;

            return new SafeHttpResponse($current, $status, $type, substr($body, 0, self::MAX_BYTES), $truncated);
        }

        throw new SafeHttpException('Too many redirects.');
    }

    /**
     * @return array{0:string,1:string} host and the pinned public IP
     *
     * @throws SafeHttpException
     */
    public function validate(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new SafeHttpException('Only absolute https:// URLs are allowed.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new SafeHttpException('Credentials in URLs are not allowed.');
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new SafeHttpException('Non-standard ports are not allowed.');
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            throw new SafeHttpException('IP-address URLs are not allowed.');
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local') || ! str_contains($host, '.')) {
            throw new SafeHttpException('Internal hostnames are not allowed.');
        }

        $ips = $this->resolve($host);
        if ($ips === []) {
            throw new SafeHttpException('Host could not be resolved.');
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new SafeHttpException('Host resolves to a non-public address.');
            }
        }

        return [$host, $ips[0]];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            $blocked = [
                ['100.64.0.0', 10],  // carrier-grade NAT
                ['192.0.0.0', 24],   // IETF protocol assignments
                ['198.18.0.0', 15],  // benchmarking
                ['169.254.0.0', 16], // link-local / cloud metadata
                ['0.0.0.0', 8],
            ];
            foreach ($blocked as [$network, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($long & $mask) === (ip2long($network) & $mask)) {
                    return false;
                }
            }

            return true;
        }

        $lower = strtolower($ip);

        // IPv4-mapped IPv6, unique-local, link-local, documentation ranges.
        return ! (str_starts_with($lower, '::ffff:') || str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd')
            || str_starts_with($lower, 'fe80') || str_starts_with($lower, '2001:db8') || $lower === '::1' || $lower === '::');
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        if (self::$resolver) {
            return (self::$resolver)($host);
        }

        $ips = [];
        foreach ([DNS_A, DNS_AAAA] as $type) {
            $records = @dns_get_record($host, $type) ?: [];
            foreach ($records as $record) {
                $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
            }
        }

        return array_values(array_unique(array_filter($ips)));
    }

    /** @throws SafeHttpException */
    private function request(string $url, string $host, string $ip): ResponseInterface
    {
        $pin = str_contains($ip, ':') ? "[{$ip}]" : $ip;

        try {
            return (new Client)->get($url, [
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::CONNECT_TIMEOUT => 5,
                RequestOptions::TIMEOUT => 12,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::HEADERS => [
                    'User-Agent' => 'StatementraVerifier/1.0 (+https://'.config('statementra.brand.domain').'/bot)',
                    'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.8,application/pdf;q=0.5',
                    'Accept-Language' => 'en',
                ],
                RequestOptions::PROGRESS => function ($expected, $downloaded) {
                    if ($expected > self::MAX_BYTES * 5 || $downloaded > self::MAX_BYTES) {
                        throw new SafeHttpException('Response too large.');
                    }
                },
                'curl' => [
                    CURLOPT_RESOLVE => ["{$host}:443:{$pin}"],
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                ],
            ]);
        } catch (SafeHttpException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            throw new SafeHttpException('Request failed: '.$e->getMessage(), previous: $e);
        }
    }

    private function absolute(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = 'https://'.$parts['host'];

        return str_starts_with($location, '/')
            ? $origin.$location
            : $origin.rtrim(dirname($parts['path'] ?? '/'), '/').'/'.$location;
    }
}
