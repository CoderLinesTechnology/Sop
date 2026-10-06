# Statementra architecture

> Simple for the customer. Sophisticated behind the scenes.

Statementra is a Laravel 13 (PHP 8.3+) application on MySQL 8. The public site is
server-rendered Blade + Tailwind CSS v4 with a few small Alpine.js (CSP build)
components; the admin panel is Filament 5. Long-running work runs on Laravel
queues (database driver by default) and the scheduler.

```
Customer ──► Public site (Blade) ──► Draft order (FORM_SUBMITTED)
                                  ──► Checkout (server-side price) ──► Paystack
Paystack ──► Webhook (HMAC-SHA512) ──► PaymentConfirmationService (verify API, row locks)
                                  ──► PipelineDispatcher::startForOrder (idempotent)
Queue worker ──► AI pipeline stages (OpenAI Responses API + web search)
             ──► Document engine (DocumentModel ─► PDF + DOCX, file QA)
             ──► DocumentDelivery (email with attachments) ──► DELIVERED
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
| `app/Domain/Ai` | AI pipeline: `PipelineDispatcher`, stages, OpenAI client, fake provider, budgets |
| `app/Domain/Documents` | Requirements & templates, `DocumentModel`, PDF/DOCX renderers, file QA |
| `app/Domain/Notifications` | `AdminNotifier` (Filament database notifications + email + webhook) |
| `app/Support` | `Settings` (admin settings, cached), `Money`, `Audit`, `SecurityLog`, `Analytics`, `SafeHttp` |
| `app/Filament` | Admin panel resources, pages and widgets |
| `app/Http` | Public controllers, middleware (`SecurityHeaders`, `AuthorizeOrderAccess`, ...) |
| `app/Jobs` | Queue jobs (payments, email, extraction, pipeline stages) |

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
- Revisions: `RevisionService::request()` / `begin()` → `PipelineDispatcher::startRevision()`.
- Admin overrides: `PipelineDispatcher` (pause/resume/retry/skip/cancel/regenerate), `DocumentAdminOperations`, `DocumentDelivery::resend()`, `RefundService`, `InformationRequestService`, `OrderStateMachine` (force with reason), `OrderAccess::rotate()`.

## Queues

| Queue | Connection | Jobs |
| --- | --- | --- |
| `payments` | database | webhook processing, reconciliation |
| `emails` | database | `SendEmailMessage` (5 tries, exponential backoff) |
| `default` | database | text extraction, misc |
| `ai` | database-long (retry_after 960s) | pipeline stages |

Run workers with `php artisan queue:work database --queue=payments,emails,default` and
`php artisan queue:work database-long --queue=ai --timeout=900`.

## Testing

Pest 4 against MySQL (`phpunit.xml`). Run a suite on its own database with
`DB_DATABASE=statementra_test_x ./vendor/bin/pest tests/Feature/X`. Helpers in `tests/Pest.php`
(`actingAsAdmin(AdminRole)`). External services are faked: `Http::fake()` for Paystack/OpenAI,
`AI_PROVIDER=fake`, `Storage::fake()`, `Mail::fake()` / `Queue::fake()`.
