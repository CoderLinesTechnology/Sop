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
| Referrer personal data (data-protection laws above) | Lawful basis: contract (the referral agreement). Collect only name, email, optional phone and admin notes; show referrers counts and money only, never customers' data or ids; erase path anonymises name, email, phone, notes and payment details while keeping the commission ledger, payout records and order attribution (accounting records); refused while a payout request is open | `Referrer`, `ReferrerService::erase()` (Admin → Referrers → Erase personal data), `PartnerDashboard`. **Decision needed**: retention period for referrer records, their ledger and payout records (tax/accounting), and whether audit-log entries that hold a referrer's details are kept after erasure (they are today; the audit log is insert-only) |
| Referrer payment details (financial data) | Collected only from approved partners, from their dashboard (never on the application form); a first addition or a change applies only after the partner confirms it from a single-use 48-hour link emailed to their address (a leaked dashboard link cannot redirect money). Stored encrypted (APP_KEY) on the referrer, snapshotted encrypted on each payout request; shown masked everywhere (“MTN MoMo •••• 4567, A. Mensah”) and in full only to administrators with `referrals.payouts`, each reveal audited; never in audit snapshots, logs or emails (emails show the masked form); unconfirmed changes cleared after expiry; erased with the referrer (the payout records keep their snapshot as accounting records). Payout screens warn when details changed within 14 days of a request | `ReferrerPaymentDetails`, `PaymentDetails`, `ReferralPayouts`, `ReferralPayoutPolicy::viewPaymentDetails`, `ReferralHousekeeping`. **Decision needed**: how long payout records (with the recipient account) are kept, and the payout timing statement in the partner terms |
| Partner applications | Lawful basis: steps at the applicant's request before a contract, with a required acknowledgement of the privacy notice and agreement to be contacted. Collect name, email, phone, city, country, promotion channels and details, audience size, up to three profile links, motivation, optional preferred code; no payment details, IP address or user agent. Counts only after email confirmation (single-use 48-hour link; the GET page shows a button, only the POST confirms); unconfirmed applications deleted after 7 days, rejected ones after `referrals.rejected_application_days` (default 180); admins can delete an application on an erasure request (audited without personal data); honeypot, timing check and rate limits per IP and per email | `PartnerApplicationController`, `PartnerApplications`, `ReferralHousekeeping`, Admin → Pricing → Partner applications. **Decision needed**: confirm the 180-day retention for rejected applications and how long approved applications are kept (today: until deleted), and the privacy-notice wording for applicants |
| Referral cookie `st_ref` | **Decided (owner):** a referral link is remembered for the visit in the server-side session only (the existing, strictly necessary session cookie; legitimate interest, to be disclosed in the privacy notice). The 30-day first-party `st_ref` cookie (HttpOnly, SameSite=Lax, Secure; holds only the referrer's code; lifetime `referrals.cookie_days`) is set only when the visitor answers “Remember” in a small consent prompt, shown at most once per session per code; “No thanks” keeps the referral for the session only. Global Privacy Control (`Sec-GPC: 1`) is respected: no prompt and no cookie, session only. Visit counts are daily aggregates per referrer (no IP, user agent or visitor id stored); switchable with `referrals.enabled` | `CaptureReferral`, `ReferralConsentPrompt`, `ReferralConsentController`, `ReferralVisits`. Owner item: privacy-notice and cookie-notice wording for the session attribution and the consented cookie |
| Order attribution and commission | Attribution only records which referrer an order came from (never changes the price); the session's link first, then the consented cookie, and a code typed at checkout overrides both; no customer data is shared with the referrer; self-referral (same email) earns nothing; commission is a fixed percentage of what the customer paid, reversed in proportion to refunds; earnings become withdrawable after `referrals.hold_days` (14) and are paid out only through payout requests of the whole available balance from `referrals.minimum_payout` (GH₵100.00) | `ReferralAttribution`, `ReferralCommissions`, `ReferralBalances`, `ReferralPayouts`. **Decision needed**: privacy-notice text telling customers that orders reached through a referral are attributed to that referrer, and partner terms (rate, hold period, minimum and payout timing, payout methods, tax, refunds and clawback including negative balances, self-referral, cookie window) — legal text for the owner, not engineering |
| Partner-programme emails | Sent through the existing email provider (declared processor): application confirmation, application received, approved (share and dashboard links), not accepted (optional reason), payout requested, payout paid (reference), payout not paid (reason), payment-details confirmation (masked details). Templates are editable in the admin; links point only at this site | `EmailTemplateKey`, `DefaultEmailTemplates`, `EmailTemplateSeeder`. Note: like other link emails, bodies containing private links are kept in the email log |

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
