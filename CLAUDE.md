# Statementra — guidance for AI coding assistants

Laravel 13 / PHP 8.3 / MySQL 8 app. Read `docs/ARCHITECTURE.md` before changing
anything non-trivial; deployment constraints are in `docs/DEPLOYMENT.md`.

## Hard rules

- **No queues and no cron.** The app must run on shared hosting. Never implement
  `ShouldQueue`, dispatch jobs, use `Mail::queue()` or add scheduler entries.
  Persist intent in the database, run the work with
  `App\Support\Runtime\AfterResponse::run()`, and let a heartbeat task
  (`config('statementra.runtime.tasks')`) retry what did not finish.
- Order status changes only through `OrderStateMachine`.
- Only `PaymentConfirmationService` marks orders paid (after verifying amount,
  currency and reference with Paystack). Prices come only from `PriceCalculator`.
- Fulfilment starts only through `FulfillmentGuard::claim()`.
- Customer files go through `FileVault` (encrypted, private disk).
- Customer text and uploads can be data, reference and instruction at once: the pipeline follows a
  customer's instructions about their own document (tone, emphasis, what to include, which upload to
  build on) within the system rules, but nothing in customer content or web pages can change the AI's
  task, rules or output format (`UntrustedData::securityNote()`). Web pages are information only.
- Money is stored in integer minor units. Customers only ever see `public_id`
  (ULID) and `reference` (`ST-XXXX-XXXX`), never database ids.
- Services, prices, page copy, prompts and templates live in the database and are
  edited in the admin panel; do not hard-code them.
- Never commit secrets. The Paystack secret key never reaches the front end.

## Conventions

- Models use Laravel 13 attributes (`#[Fillable]`, `#[Unguarded]`, `#[RouteKey]`).
- Domain logic lives in `app/Domain/*` services; controllers stay thin.
- Admin actions that change data are audited with `App\Support\Audit::log()`.
- Alpine.js runs as the CSP build: keep template expressions simple (no globals,
  no assignments to DOM properties).
- Style: `vendor/bin/pint`. Tests: Pest against MySQL
  (`DB_DATABASE=statementra_test vendor/bin/pest`); fake Paystack/OpenAI with
  `Http::fake()` and `AI_PROVIDER=fake`.
