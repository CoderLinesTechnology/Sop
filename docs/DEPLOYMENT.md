# Deploying Statementra

Statementra runs on ordinary PHP hosting. **It does not need cron, a queue
worker, Redis or Node.js on the server.** Background work runs inside normal
web requests (see [How background work runs](#how-background-work-runs)).

## What you need

| | |
| --- | --- |
| PHP | 8.3 or newer, with `ctype`, `curl`, `dom`, `fileinfo`, `gd` (with WebP), `intl`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `zip` |
| Database | MySQL 8.0+ (tested). MariaDB 10.6+ should work but is not tested. |
| HTTPS | Required (Paystack, secure cookies, HSTS). |
| Shell access | SSH or cPanel → Terminal, for the setup commands below. |
| Accounts | Paystack (test and live keys), OpenAI API key, an SMTP mailbox or provider. |
| Optional | `pdftotext` (poppler-utils) for faster PDF text extraction, ClamAV for malware scanning, LibreOffice for an extra DOCX check. |

Recommended PHP settings (cPanel → *Select PHP Version* → *Options*, or `php.ini`):

```ini
memory_limit = 512M
max_execution_time = 300
upload_max_filesize = 12M
post_max_size = 16M
```

## 1. Build the release package

The server needs the `vendor/` folder and the compiled assets in `public/build/`.
Build them on your computer or let GitHub Actions do it:

- **GitHub Actions:** every push to `main` runs the tests and uploads a
  `statementra-release` artifact (Actions → latest run → Artifacts). It is a zip
  ready to upload.
- **On your computer** (PHP 8.3, Composer and Node 20+ installed):

  ```bash
  composer install --no-dev --optimize-autoloader
  npm ci && npm run build
  ```

  Then zip the project folder without `node_modules/`, `.git/`, `tests/` and `.env`.

## 2. Shared hosting (cPanel, LiteSpeed or Apache)

1. **Upload** the zip to your home folder (not into `public_html`) and extract it,
   for example to `~/statementra`.
2. **Point the domain at `public/`.** In cPanel → *Domains*, set the document root
   of statementra.com to `statementra/public`. Only the `public/` folder may be
   reachable from the web. If your host cannot change the document root, replace
   `public_html` with a symbolic link: `rm -rf ~/public_html && ln -s ~/statementra/public ~/public_html`
   (move anything you still need out of `public_html` first).
3. **Create the database.** cPanel → *MySQL Databases*: create a database and a user,
   and give the user all privileges on that database.
4. **Configure.** Copy `.env.example` to `.env` and fill in at least `APP_URL`,
   the `DB_*` values, `PAYSTACK_*`, `OPENAI_API_KEY`, the `MAIL_*` values and
   `FILE_ENCRYPTION_KEY` (generate it with
   `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`). Keep `APP_DEBUG=false`.
5. **Run the setup commands** in the Terminal, inside `~/statementra`:

   ```bash
   php artisan key:generate --force
   php artisan migrate --force
   php artisan db:seed --force
   php artisan storage:link
   php artisan statementra:create-admin you@yourdomain.com
   php artisan optimize
   php artisan filament:optimize
   ```

   `db:seed` installs the services, page content, email templates, AI prompts,
   document templates and country rules. It is safe to run again: it never
   overwrites what you have edited in the admin panel.
6. **Folder permissions.** `storage/` and `bootstrap/cache/` must be writable by
   PHP (usually already true on cPanel; otherwise `chmod -R 775 storage bootstrap/cache`).

## 3. Connect the services

- **Paystack** (Dashboard → Settings → API Keys & Webhooks): set the webhook URL to
  `https://statementra.com/webhooks/paystack`. The callback URL is sent with each
  payment, so it does not need to be set. Test with `PAYSTACK_MODE=test` and test
  keys first, then switch to `live` keys.
- **Email:** use the SMTP details of your mailbox or provider. Add SPF, DKIM and
  DMARC records for the sending domain so delivery emails reach inboxes.
  Optional delivery tracking: point Postmark or Resend webhooks at
  `/webhooks/email/postmark` or `/webhooks/email/resend` and set the matching
  token or secret in `.env`.
- **OpenAI:** set `OPENAI_API_KEY`. Models, prompts and the daily AI budget are
  managed in Admin → AI Control Center.

## 4. Sign in to the admin panel

Open `https://statementra.com/admin` (or your `ADMIN_PATH`) and sign in with the
account you created. You will be asked to set up two-factor authentication with
an authenticator app. Save the recovery codes.

Before going live, review Admin → Settings (support email, delivery times,
retention period), the services and prices, and the email templates, and place
a test order in Paystack test mode from start to finish.

## How background work runs

Nothing needs to be scheduled:

- Work started by a visitor (sending an email, reading an uploaded CV,
  confirming a payment) runs **right after the page has been sent**, so nobody waits.
- The AI research and writing pipeline runs stage by stage. When one request
  has worked long enough, the app sends itself a signed request
  (`/internal/pipeline/...`) to continue in a fresh one.
- Retries, payment reconciliation, reminders and data-retention clean-up run on
  a **heartbeat** that happens after ordinary page views, at most once a minute.

For a quiet site (few visitors at night, for example), add a free uptime monitor
(UptimeRobot, Better Stack, cron-job.org...) that opens
`https://statementra.com/system/heartbeat/{token}` every 5 minutes. The full URL is
in Admin → Settings → System, which also shows when each maintenance task last
ran and whether it succeeded.

If your server cannot reach its own public address (some firewalls block it),
set `RUNTIME_LOOPBACK_URL=http://127.0.0.1` in `.env`. The heartbeat and the
customer's status page also keep orders moving if self-requests fail.

## VPS or dedicated server (Nginx + PHP-FPM)

The same steps apply. A minimal Nginx site:

```nginx
server {
    listen 443 ssl http2;
    server_name statementra.com;
    root /var/www/statementra/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/statementra.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/statementra.com/privkey.pem;

    client_max_body_size 16M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

In the PHP-FPM pool, allow long after-response work and enough workers:
`request_terminate_timeout = 900` and `pm.max_children` sized to your memory
(about 60–80 MB per worker). No Supervisor or cron entries are needed.

## Updating

```bash
php artisan down --retry=60
# upload and extract the new release over the old one (keep .env and storage/)
php artisan migrate --force
php artisan db:seed --force
php artisan optimize
php artisan filament:optimize
php artisan up
```

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Orders stay at "Payment confirmed" | Admin → Settings → System: is the heartbeat recent? Set up the uptime ping. If self-requests fail (`storage/logs`), set `RUNTIME_LOOPBACK_URL`. |
| Payments not confirmed | Paystack webhook URL and keys; Admin → Payments shows each webhook and its processing notes. |
| Emails not arriving | SMTP settings; Admin → Emails shows each attempt and error; failed sends are retried automatically. |
| Blank or unstyled admin panel | Run `php artisan filament:assets` and `php artisan optimize`. |
| "500 Server Error" | `storage/logs/laravel.log`; `storage/` and `bootstrap/cache/` must be writable. |
