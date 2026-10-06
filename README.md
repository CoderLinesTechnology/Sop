# Statementra

Premium, research-led application writing at [statementra.com](https://statementra.com).
A customer chooses a service (personal statement, statement of purpose, motivation
letter, scholarship essay...), tells us about themselves, uploads a CV, pays with
Paystack — no account needed — and about 20–30 minutes later receives a
researched, fact-checked, professionally formatted document as **PDF and editable
DOCX** by email.

Behind the scenes a multi-stage AI pipeline (OpenAI Responses API with web search)
reads the customer's material, researches the programme and institution, verifies
claims and requirements, writes and edits the document, checks it against the
country's and institution's rules, renders both files from one structure and
checks them before delivery.

## Stack

- Laravel 13, PHP 8.3+, MySQL 8
- Public site: Blade, Tailwind CSS v4, Alpine.js (CSP build)
- Admin panel: Filament 5 with mandatory two-factor authentication and role-based permissions
- Payments: Paystack (server-side initialisation, signed webhooks, server-to-server verification)
- Documents: mPDF and PhpWord, with text, length and font checks before delivery
- **Runs on shared hosting: no cron, no queue worker, no Redis**

## Local development

Requirements: PHP 8.3+, Composer, Node 20+, MySQL 8.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

In `.env`, set `APP_ENV=local`, `APP_DEBUG=true`, `APP_URL=http://localhost:8000`,
your database credentials, and for a fully offline setup:

```dotenv
PAYSTACK_MODE=mock     # a built-in fake checkout page instead of Paystack
AI_PROVIDER=fake       # deterministic fake AI output instead of OpenAI
MAIL_MAILER=log        # emails are written to storage/logs
```

Then:

```bash
php artisan migrate --seed
php artisan storage:link
npm run build          # or `npm run dev` while working on the front end
php artisan serve
```

- Site: http://localhost:8000
- Admin: http://localhost:8000/admin — `admin@statementra.test` / `Statementra!Admin2026`
  (local seed only; you will be asked to set up two-factor authentication).

With `PAYSTACK_MODE=mock`, the payment step opens a local test checkout where you
choose success or failure; a signed webhook is delivered exactly as Paystack would.

## Tests

```bash
php artisan test          # or vendor/bin/pest
vendor/bin/pint --test    # code style
```

Tests run against MySQL (`statementra_test`, see `phpunit.xml`); Paystack, OpenAI
and email are faked. CI (GitHub Actions) runs both on every push and builds an
upload-ready release zip from `main`.

## Documentation

- [Deployment](docs/DEPLOYMENT.md): shared hosting (cPanel) and VPS, step by step
- [Architecture](docs/ARCHITECTURE.md): subsystems, rules, order lifecycle, the request-driven runtime

## Security

Report vulnerabilities privately to security@statementra.com. Never commit `.env`
or API keys; the Paystack secret key is only ever used on the server.
