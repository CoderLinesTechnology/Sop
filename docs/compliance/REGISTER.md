# Compliance register

First draft, derived from what the product does (2026-10-09). It is an engineering
checklist, not legal advice: items marked **Decision needed** must be confirmed by the
person responsible for privacy/legal before they are relied on.

Statementra processes applicants' personal data (identity, contact details, education
and work history, uploaded CVs/transcripts, sometimes sensitive details they choose to
share), takes card/mobile-money payments through Paystack, and sends that material to an
AI provider to write documents submitted under the applicant's name.

| Obligation | Engineering requirements | Where in the code |
| --- | --- | --- |
| Data protection: Ghana DPA 2012 (Act 843); Nigeria NDPA 2023 / Kenya DPA 2019 where customers are served; UK/EU GDPR where the service targets applicants there | Collect only what a document needs; private, encrypted storage; no internal ids in customer-facing URLs; retention limit and erasure; record of processors | `FileVault` (AES-256-GCM), `public_id`/`reference` routing, `PurgeExpiredOrderData` + `OrderDataPurger` (`orders.retention_days`), `Audit::log()` |
| Processors and international transfers | Every outbound flow of personal data is declared: OpenAI (prompts; `store` off by default), Paystack (payments), the email provider (Resend/Postmark/SMTP), ClamAV (self-hosted) | `LlmGateway`, `OpenAiProvider`, `Domain/Payments/Paystack`, `EmailSender` |
| Payments: PCI DSS (SAQ A via Paystack-hosted checkout) | No card data touches the app; secret key server-side only; webhook signatures verified; amounts verified server-to-server | `PaymentConfirmationService`, `PaystackWebhookController` |
| AI transparency and safety (consumer protection; EU AI Act transparency where applicable) | Customers are told the documents are AI-assisted; untrusted text is data, never instructions; no invented facts | `UntrustedData`, `FactCheckStage`, public FAQ/terms (**Decision needed**: confirm wording) |
| Security baseline: OWASP ASVS L2 | Authz on every admin action, upload validation and malware scanning, audit trail, rate limits, MFA for admins | Policies, `UploadValidator`, `MalwareScanner`, `SecurityLog`, `AdminSessionTimeout` |
| Accessibility: WCAG 2.2 AA | Public site and admin forms usable by keyboard and screen reader | Blade views, Filament panel |
| Cookies / tracking | Analytics beacon and any non-essential cookies need consent where the law requires it | `BeaconController` (**Decision needed**: confirm consent approach) |

## Writing samples (AI → Writing samples)

Administrators upload example SOPs, letters, essays and CVs; a few matching samples are
sent to OpenAI as style references while other customers' documents are written.

- **Risk:** a sample may contain the personal data of its author (or of a past customer).
  Sending it to the AI for someone else's order is a new purpose; a past customer's
  document would also outlive the order retention period.
- **Engineering controls:** an administrator must confirm that each document is
  anonymised or that its author agreed — when it is added and again whenever its file is
  replaced (`writing_samples.rights_confirmed_at` / `rights_confirmed_by_admin_id`, plus the
  audit log); email addresses, phone numbers and links are redacted on import and on every
  edit (`WritingSampleImporter::redact()`), while names are not detected automatically, so
  the edit page asks the administrator to remove them; the uploaded file (including the
  temporary upload copy) is discarded and only the text is kept, encrypted at rest; titles are
  never sent to the AI; samples reach the model as untrusted data; wording copied from a
  sample is removed before delivery (`WritingSampleOverlap` in `FactCheckStage`);
  deleting a sample erases it (no copies are stored per order, only ids in
  `ai_jobs.writing_sample_ids`); create/update/delete are audited without the text.
- **Decision needed:** whether past customers' documents may be used at all. If yes,
  obtain explicit consent (separate from the order terms) and mention the use in the
  privacy notice; otherwise use only anonymised or publicly licensed samples.
