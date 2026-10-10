<?php

use App\Domain\Ai\Tasks\AdvanceDuePipelines;
use App\Domain\Ai\Tasks\NotifyDelayedOrders;
use App\Domain\Ai\Tasks\RecoverStalledPipelines;
use App\Domain\Email\Tasks\SendPendingEmails;
use App\Domain\Files\Tasks\ExtractPendingUploads;
use App\Domain\Maintenance\ProcessInformationRequests;
use App\Domain\Maintenance\PruneAbandonedDrafts;
use App\Domain\Maintenance\PruneOperationalData;
use App\Domain\Maintenance\PurgeExpiredOrderData;
use App\Domain\Payments\Tasks\ProcessPendingPaymentEvents;
use App\Domain\Payments\Tasks\ReconcilePayments;
use App\Domain\Payments\Tasks\StartPendingFulfilment;
use App\Domain\Seo\Tasks\SendIndexNowPings;

/*
|--------------------------------------------------------------------------
| Statementra configuration
|--------------------------------------------------------------------------
|
| Infrastructure and secrets come from the environment. Everything a
| business user should change (prices, copy, delivery estimates, AI
| thresholds, templates...) lives in the database and is edited in the
| admin panel; the values here are only fallbacks.
|
*/

return [

    'brand' => [
        'name' => env('APP_NAME', 'Statementra'),
        'domain' => env('BRAND_DOMAIN', 'statementra.com'),
        'tagline' => 'Your story. Researched. Written. Refined.',
    ],

    'paystack' => [
        // "live", "test" or "mock" (mock is refused when APP_ENV=production).
        'mode' => env('PAYSTACK_MODE', 'test'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        'timeout' => (int) env('PAYSTACK_TIMEOUT', 20),
        // Optional defence in depth: only accept webhooks from Paystack's published IPs.
        'enforce_webhook_ips' => (bool) env('PAYSTACK_ENFORCE_WEBHOOK_IPS', false),
        'webhook_ips' => array_filter(explode(',', (string) env('PAYSTACK_WEBHOOK_IPS', '52.31.139.75,52.49.173.169,52.214.14.220'))),
        'channels' => array_filter(explode(',', (string) env('PAYSTACK_CHANNELS', 'card,bank,ussd,bank_transfer,mobile_money'))),
    ],

    'ai' => [
        // "openai" in production; "fake" produces deterministic demo output for
        // local development and tests and is refused when APP_ENV=production.
        'provider' => env('AI_PROVIDER', 'openai'),
        'openai_api_key' => env('OPENAI_API_KEY'),
        'openai_base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'openai_organization' => env('OPENAI_ORGANIZATION'),
        'openai_project' => env('OPENAI_PROJECT'),
        'request_timeout' => (int) env('OPENAI_TIMEOUT', 300),
        'default_model' => env('AI_DEFAULT_MODEL', 'gpt-6.1-sol'),
        'writing_model' => env('AI_WRITING_MODEL', 'gpt-6-astra'),
        'store_responses' => (bool) env('OPENAI_STORE_RESPONSES', false),
    ],

    'storage' => [
        // Private disk for customer uploads and generated documents.
        'private_disk' => env('PRIVATE_FILES_DISK', 'private'),
        // Public disk for CMS images (logos, article covers, hero images).
        'public_disk' => env('PUBLIC_MEDIA_DISK', 'public'),
        // base64-encoded 32-byte key for application-level AES-256-GCM file encryption.
        'encryption_key' => env('FILE_ENCRYPTION_KEY'),
        'encryption_key_id' => env('FILE_ENCRYPTION_KEY_ID', 'k1'),
        // Previous keys for rotation: "k0:base64key,k-1:base64key".
        'previous_keys' => env('FILE_ENCRYPTION_PREVIOUS_KEYS'),
    ],

    'scanning' => [
        // "clamav" scans every upload through clamd; "none" skips scanning (dev only).
        'driver' => env('MALWARE_SCANNER', 'none'),
        'clamav_host' => env('CLAMAV_HOST', '127.0.0.1'),
        'clamav_port' => (int) env('CLAMAV_PORT', 3310),
        'clamav_socket' => env('CLAMAV_SOCKET'),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
        // When true, uploads are rejected if the scanner is unavailable.
        'fail_closed' => (bool) env('MALWARE_SCAN_FAIL_CLOSED', true),
    ],

    'documents' => [
        'pdftotext_binary' => env('PDFTOTEXT_BINARY', '/usr/bin/pdftotext'),
        // Optional: convert generated DOCX files with LibreOffice to verify they open.
        'libreoffice_binary' => env('LIBREOFFICE_BINARY', 'soffice'),
        'verify_docx_with_libreoffice' => (bool) env('VERIFY_DOCX_WITH_LIBREOFFICE', false),
        'fonts_path' => resource_path('fonts/document'),
    ],

    'email' => [
        // Provider webhook verification (delivery tracking).
        'resend_webhook_secret' => env('RESEND_WEBHOOK_SECRET'),
        'postmark_webhook_token' => env('POSTMARK_WEBHOOK_TOKEN'),
        'max_attachment_mb' => (int) env('EMAIL_MAX_ATTACHMENT_MB', 15),
    ],

    'security' => [
        'admin_path' => env('ADMIN_PATH', 'admin'),
        'admin_ip_allowlist' => array_filter(explode(',', (string) env('ADMIN_IP_ALLOWLIST', ''))),
        'hsts' => (bool) env('SECURITY_HSTS', env('APP_ENV') === 'production'),
        'trusted_proxies' => env('TRUSTED_PROXIES', '*'),
        'turnstile_site_key' => env('TURNSTILE_SITE_KEY'),
        'turnstile_secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    'seo' => [
        // IndexNow notifications are only sent from production; tests switch this on to exercise them.
        'indexnow_outside_production' => (bool) env('INDEXNOW_OUTSIDE_PRODUCTION', false),
    ],

    'monitoring' => [
        'alert_webhook_url' => env('ALERT_WEBHOOK_URL'),
    ],

    /*
    | Request-driven runtime: Statementra needs no queue worker and no cron.
    | Slow work runs after the response is sent; the AI pipeline continues
    | through signed requests to itself; maintenance runs on a heartbeat that
    | piggybacks on site traffic (and, optionally, an external uptime pinger
    | calling /system/heartbeat/{token}).
    */
    'runtime' => [
        // Base URL for requests the app makes to itself. Defaults to APP_URL;
        // set it (e.g. http://127.0.0.1) if the server cannot reach its public URL.
        'loopback_url' => env('RUNTIME_LOOPBACK_URL'),
        'loopback_verify_tls' => (bool) env('RUNTIME_LOOPBACK_VERIFY_TLS', true),
        'heartbeat_on_traffic' => (bool) env('RUNTIME_HEARTBEAT_ON_TRAFFIC', true),
        // Secret path segment for the external ping URL; generated and stored in settings when empty.
        'heartbeat_token' => env('RUNTIME_HEARTBEAT_TOKEN'),
        // "request" (default): AI stages run inside web requests (after the response).
        // "process": web requests start a detached `php artisan statementra:pipeline-run`
        // instead — for hosts that stop requests after a couple of minutes (Hostinger).
        'pipeline_driver' => env('RUNTIME_PIPELINE_DRIVER', 'request'),
        'php_binary' => env('RUNTIME_PHP_BINARY', '/usr/bin/php'),
        'heartbeat_min_gap_seconds' => 60,
        'heartbeat_budget_seconds' => 25,

        // Heartbeat tasks in priority order: name => [run at most every N seconds, invokable class].
        'tasks' => [
            'payments.process-events' => [60, ProcessPendingPaymentEvents::class],
            'orders.start-pending' => [120, StartPendingFulfilment::class],
            'ai.advance-due' => [60, AdvanceDuePipelines::class],
            'ai.recover-stalled' => [300, RecoverStalledPipelines::class],
            'ai.notify-delayed' => [300, NotifyDelayedOrders::class],
            'emails.send-pending' => [60, SendPendingEmails::class],
            'uploads.extract-pending' => [120, ExtractPendingUploads::class],
            'payments.reconcile' => [600, ReconcilePayments::class],
            'orders.information-requests' => [900, ProcessInformationRequests::class],
            'orders.prune-drafts' => [3600, PruneAbandonedDrafts::class],
            'orders.purge-expired' => [21600, PurgeExpiredOrderData::class],
            'system.prune-logs' => [86400, PruneOperationalData::class],
            'seo.indexnow' => [600, SendIndexNowPings::class],
        ],
    ],
];
