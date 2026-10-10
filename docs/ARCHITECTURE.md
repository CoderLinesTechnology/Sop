# Statementra architecture

> Simple for the customer. Sophisticated behind the scenes.

Statementra is a Laravel 13 (PHP 8.3+) application on MySQL 8. The public site is
server-rendered Blade + Tailwind CSS v4 with a few small Alpine.js (CSP build)
components; the admin panel is Filament 5.

**No queue worker and no cron are needed.** Everything runs inside ordinary web
requests: slow work runs right after the response is sent, the AI pipeline
continues stage by stage through signed requests the app makes to itself, and
maintenance runs on a heartbeat driven by site traffic. This keeps the app
deployable on shared hosting (see [Runtime](#runtime-no-queue-worker-no-cron)).

```
Customer ──► Public site (Blade) ──► Draft order (FORM_SUBMITTED)
                                  ──► Checkout (server-side price) ──► Paystack
Paystack ──► Webhook (HMAC-SHA512) ──► 200 at once, then PaymentConfirmationService
                                      (verify API, row locks) ──► PipelineDispatcher::startForOrder
After the response ──► PipelineWorker: AI stages (OpenAI Responses API + web search)
   │                ──► Document engine (DocumentModel ─► PDF + DOCX, file QA)
   │                ──► DocumentDelivery (email with attachments) ──► DELIVERED
   └─ next stage in a fresh request: signed POST /internal/pipeline/{job} (SelfTrigger)
Page views / status polling / uptime ping ──► Heartbeat: retries, reconciliation, retention
```

## Directory map

| Path | What lives there |
| --- | --- |
| `app/Enums` | Order lifecycle, payment, field, pipeline, role and permission enums |
| `app/Models` | Eloquent models (configured with Laravel 13 attributes: `#[Fillable]`, `#[Table]`, `#[RouteKey]`) |
| `app/Domain/Pricing` | `PriceCalculator` (the only price authority), `PromotionResolver`, `CouponValidator`, `CouponReservations` |
| `app/Domain/Orders` | `OrderStateMachine`, `FulfillmentGuard`, `OrderAccess` (signed links), `CheckoutSession`, `InformationRequestService`, `RevisionService` |
| `app/Domain/Payments` | `CheckoutService`, `PaymentConfirmationService`, `RefundService`, Paystack client / mock / webhook signature |
| `app/Domain/Files` | `UploadService`, `UploadValidator`, `MalwareScanner` (ClamAV), `FileEncryptor` (AES-256-GCM), `FileVault`, `TextExtractor` |
| `app/Domain/Email` | `TransactionalMailer`, `TemplateRenderer`, `DefaultEmailTemplates`, `OrderEmailVariables` |
| `app/Domain/Delivery` | `DocumentDelivery` (attach PDF+DOCX, mark DELIVERED / DELIVERY_FAILED) |
| `app/Domain/Ai` | AI pipeline: `PipelineDispatcher`, stages, OpenAI client, fake provider, budgets; `Samples/`: admin-uploaded writing samples (import, per-job selection, copy detection) |
| `app/Domain/Documents` | Requirements & templates, `DocumentModel`, PDF/DOCX renderers, file QA |
| `app/Domain/Notifications` | `AdminNotifier` (Filament database notifications + email + webhook) |
| `app/Support` | `Settings` (admin settings, cached), `Money`, `Audit`, `SecurityLog`, `Analytics`, `SafeHttp` |
| `app/Support/Runtime` | `AfterResponse`, `SelfTrigger`, `Heartbeat`: the request-driven runtime |
| `app/Domain/Maintenance` | Heartbeat tasks: draft pruning, information-request reminders, retention purge, log pruning |
| `app/Filament` | Admin panel resources, pages and widgets |
| `app/Http` | Public controllers, middleware (`SecurityHeaders`, `AuthorizeOrderAccess`, ...) |

## Non-negotiable rules

1. **Order status changes only through `OrderStateMachine`** (`transition()` / `transitionIfAllowed()`), never by assigning `status`.
2. **Only `PaymentConfirmationService` marks orders paid**, after server-to-server verification of amount, currency and reference.
3. **Prices come only from `PriceCalculator`.** Never read a price, discount or total from a request.
4. **Fulfilment starts only through `FulfillmentGuard::claim()`**, which is atomic and checks the verified payment.
5. **Customer files go through `FileVault`** (encrypted, random paths, private disk). Never put customer files on the public disk.
6. **Untrusted text** (customer answers, uploaded documents, web pages) is data, never instructions. Wrap it in delimited blocks when sending it to a model.
7. **Sensitive admin actions are audited** with `App\Support\Audit::log()`; suspicious activity with `App\Support\SecurityLog::record()`.
8. **Never expose internal ids** to customers. Orders use `public_id` (ULID) in URLs and `reference` (e.g. `ST-7KQ3-M9XD`) for support.
9. Customer-facing errors are friendly; technical details go to logs, `ai_jobs.last_error_*` and admin notifications.

## Order lifecycle

`NEW → FORM_SUBMITTED → PAYMENT_PENDING → PAYMENT_CONFIRMED → RESEARCHING → RESEARCH_COMPLETE → WRITING → QUALITY_REVIEW → FINAL_REVIEW → DELIVERY_PENDING → DELIVERED`, with `PAYMENT_FAILED`, `PAYMENT_EXPIRED`, `NEEDS_INFORMATION`, `PROCESSING_FAILED`, `MANUAL_REVIEW`, `DELIVERY_FAILED`, `CANCELLED`, `REFUNDED`, `PARTIALLY_REFUNDED`. The allowed graph is in `OrderStateMachine::TRANSITIONS`. Pausing is a flag (`orders.paused_at`), not a status.

## Pipeline stages → order status

| Stages | Order status |
| --- | --- |
| ingestion, analysis, research, verification, requirements | RESEARCHING (→ RESEARCH_COMPLETE) |
| strategy, writing, editorial | WRITING |
| fact_check, quality_review | QUALITY_REVIEW |
| limits, formatting, rendering, file_qa | FINAL_REVIEW |
| delivery | DELIVERY_PENDING → DELIVERED (when the email provider accepts the message) |

## Contracts between subsystems

- Payments → AI: `PipelineDispatcher::startForOrder(Order)` (idempotent, dedupe key `order:{id}:pipeline`).
- AI → Orders: `InformationRequestService::request(Order, questions)`; customers' answers call `PipelineDispatcher::resume()`.
- AI → Documents: `RequirementResolver::resolve()`, `TemplateResolver::resolve()`, `DocumentFactory::createVersion()`, `DocumentRenderer::render()`, `DocumentQa::validate()`.
- AI → Delivery: `DocumentDelivery::deliver(Order, DocumentVersion, ?Revision)`.
- Writing samples → AI: `WritingSampleSelector::forJob()` picks a job's samples once (stored in `ai_jobs.writing_sample_ids`); strategy, writing, editorial, refinement and revision calls receive them as untrusted data, and `FactCheckStage` removes wording copied from them (`WritingSampleOverlap`). Compliance notes: `docs/compliance/REGISTER.md`.
- Revisions: `RevisionService::request()` / `begin()` → `PipelineDispatcher::startRevision()`.
- Admin overrides: `PipelineDispatcher` (pause/resume/retry/skip/cancel/regenerate), `DocumentAdminOperations`, `DocumentDelivery::resend()`, `RefundService`, `InformationRequestService`, `OrderStateMachine` (force with reason), `OrderAccess::rotate()`.

## Runtime (no queue worker, no cron)

Many hosts (shared hosting in particular) cannot keep a queue worker running or
run cron reliably, so Statementra does not depend on either. `QUEUE_CONNECTION`
is `sync` and the codebase contains no queued jobs.

| Need | How it runs |
| --- | --- |
| Slow work triggered by a request (emails, upload text extraction, webhook processing, admin alerts) | `AfterResponse::run($label, fn () => ...)`: Laravel `defer()` with `ignore_user_abort` and a time limit. PHP-FPM / LiteSpeed release the visitor's connection first. In console and tests it runs at once (after the surrounding transaction commits). |
| The AI pipeline (minutes of work) | `PipelineDispatcher::kick()` runs stages after the response; when its time budget is spent the worker calls `SelfTrigger::fire('internal.pipeline.continue')`, an HMAC-signed POST to the app itself, so the next stage gets a fresh PHP request. Jobs are claimed with a lease (`ai_jobs.leased_until`), retries wait in `ai_jobs.next_run_at`. The customer's status-page polling calls `kickIfDue()`. |
| Retries and safety nets (payment events, emails, extraction, reconciliation, stalled pipelines, paid orders not yet started, IndexNow notifications) | Heartbeat tasks |
| Housekeeping (draft pruning, information-request reminders/expiry, retention purge, log pruning) | Heartbeat tasks |

**Heartbeat.** `App\Support\Runtime\Heartbeat` runs the tasks listed in
`config('statementra.runtime.tasks')` (`name => [interval seconds, invokable class]`).
A beat happens after ordinary page views (at most once a minute, after the
response), whenever `GET /system/heartbeat/{token}` is called (point any free
uptime monitor at it for quiet sites; the token is in the admin settings), or by
hand with `php artisan statementra:heartbeat`. Each task is claimed with a
conditional update on its `system_tasks` row, so it runs at most once per
interval even when beats overlap; a beat stops starting tasks after its time
budget (25 s) and the rest run next time. `system_tasks` records the last run,
status and error of every task.

**Writing new background work.** Persist the intent first (a row with a status),
then call `AfterResponse::run()` to do it now, and add or extend a heartbeat task
that picks up rows whose attempt never finished. Claim work with a conditional
`UPDATE ... WHERE status = ...` so overlapping requests cannot do it twice.
Never implement `ShouldQueue`, dispatch jobs or use `Mail::queue()`.

## Testing

Pest 4 against MySQL (`phpunit.xml`). Run a suite on its own database with
`DB_DATABASE=statementra_test_x ./vendor/bin/pest tests/Feature/X`. Helpers in `tests/Pest.php`
(`actingAsAdmin(AdminRole)`). External services are faked: `Http::fake()` for Paystack/OpenAI,
`AI_PROVIDER=fake`, per-process fake `private`/`public` disks (set up in `tests/Pest.php`),
`Mail::fake()`. After-response work runs inline in tests; use `$this->travel()` to test
retries and heartbeat intervals.
