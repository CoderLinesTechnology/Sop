<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| Proxies whose X-Forwarded-For / -Proto / -Port headers describe the real
| visitor: comma-separated IPs or CIDR ranges, or "*" only when every request
| reaches the app through a proxy that REPLACES those headers (e.g. Cloudflare
| in front of a firewalled origin). Leave TRUSTED_PROXIES empty when the web
| server already reports the visitor's address in REMOTE_ADDR (Hostinger's
| CDN does): trusting "*" there lets anyone forge their IP.
|
| The forwarded Host header is never trusted (bootstrap/app.php): links are
| always built from APP_URL, so a request cannot point emails, canonical URLs
| or the sitemap at another domain. Read by TrustProxies on every request,
| so it also works when the configuration is cached.
|
*/

$proxies = trim((string) env('TRUSTED_PROXIES', ''));

return [
    'proxies' => match (true) {
        $proxies === '' => null,
        $proxies === '*' || $proxies === '**' => $proxies,
        default => array_values(array_filter(array_map('trim', explode(',', $proxies)))),
    },
];
