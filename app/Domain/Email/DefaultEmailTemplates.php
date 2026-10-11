<?php

namespace App\Domain\Email;

use App\Enums\EmailTemplateKey as K;

/**
 * Built-in wording for every transactional email. Seeded into the database
 * (where administrators edit it) and used as a fallback if a row is missing.
 */
final class DefaultEmailTemplates
{
    public static function subject(K $key): string
    {
        return match ($key) {
            K::PaymentReceived => 'Payment received — we\'re preparing your {{service_name}}',
            K::InformationRequired => 'We need one more detail to make your document stronger',
            K::InformationReminder => 'Reminder: one quick question about your {{service_name}}',
            K::ProcessingDelay => 'We\'re still working on your document',
            K::DocumentReady => 'Your Statementra document is ready',
            K::RevisionReceived => 'We\'ve received your revision request',
            K::RevisionCompleted => 'Your revised document is ready',
            K::RefundProcessed => 'Your refund has been processed',
            K::OrderLink => 'Your secure link for order {{order_id}}',
            K::MagicLink => 'Your sign-in link',
            K::NewsletterConfirm => 'Please confirm your subscription',
            K::SupportReceived => 'We\'ve received your message',
            K::PartnerDashboardLink => 'Your {{site_name}} referral dashboard',
            K::PartnerApplicationConfirm => 'Please confirm your {{site_name}} partner application',
            K::PartnerApplicationReceived => 'We\'ve received your partner application',
            K::PartnerApplicationApproved => 'Welcome to the {{site_name}} partner programme',
            K::PartnerApplicationRejected => 'About your {{site_name}} partner application',
            K::PartnerPayoutRequested => 'We\'ve received your payout request',
            K::PartnerPayoutPaid => 'Your payout has been sent',
            K::PartnerPayoutRejected => 'About your payout request',
            K::PartnerPaymentDetailsConfirm => 'Please confirm your new payment details',
        };
    }

    public static function body(K $key): string
    {
        return match ($key) {
            K::PaymentReceived => <<<'MD'
Hi {{customer_name}},

**Payment confirmed.** Thank you — your request for a **{{service_name}}** ({{programme}}, {{institution}}) has been received and our process has started.

We're now reviewing your information, researching your programme and institution, verifying what we find and writing your personalized document. This usually takes **{{delivery_time}}**.

You don't need to keep the website open — we'll email your finished document (PDF and Word) to this address as soon as it's ready.

{{button:order_link|Track your order}}

Order reference: **{{order_id}}** · Amount paid: {{amount_paid}}

If you have any questions, just reply to this email or contact {{support_email}}.
MD,
            K::InformationRequired => <<<'MD'
Hi {{customer_name}},

We're working on your **{{service_name}}** and want to make sure it reflects your real experience. We need one more detail before we continue:

{{questions}}

It only takes a minute — your answer goes straight into your document.

{{button:order_link|Answer now}}

We never invent information, so your answer helps us write something accurate and specific to you.

Order reference: {{order_id}}
MD,
            K::InformationReminder => <<<'MD'
Hi {{customer_name}},

A quick reminder — we're waiting for one detail before we can finish your **{{service_name}}**:

{{questions}}

{{button:order_link|Answer now}}

If we don't hear back, we'll continue with the information we already have.

Order reference: {{order_id}}
MD,
            K::ProcessingDelay => <<<'MD'
Hi {{customer_name}},

**We're still working on your document.**

Your request needs a little additional processing to make sure the final document is properly researched and reviewed. We'll email you as soon as it's ready — there's nothing you need to do.

{{button:order_link|Check progress}}

Order reference: {{order_id}}
MD,
            K::DocumentReady => <<<'MD'
Hi {{customer_name}},

**Your personalized document has been completed and is ready.**

Your **{{service_name}}** for {{programme}} at {{institution}} is attached to this email in two formats:

- **PDF** — polished and ready to submit
- **Word (.docx)** — fully editable

{{section:points_to_check|Before you submit, please check these choices we made for you}}

{{button:order_link|View your document}}

**Revisions:** {{revisions_remaining}} revision(s) included until {{revision_deadline}}. Use the link above to request changes.

Order reference: {{order_id}}

Questions? Reply to this email or contact {{support_email}}. We'd love to hear how we did — you can leave quick feedback on your order page.
MD,
            K::RevisionReceived => <<<'MD'
Hi {{customer_name}},

We've received revision request **#{{revision_number}}** for your **{{service_name}}**. We'll email the updated document as soon as it's ready.

{{button:order_link|View your order}}

Order reference: {{order_id}}
MD,
            K::RevisionCompleted => <<<'MD'
Hi {{customer_name}},

**Your revised document is ready.** Revision #{{revision_number}} of your **{{service_name}}** is attached as a PDF and an editable Word document.

{{button:order_link|View your document}}

Order reference: {{order_id}}
MD,
            K::RefundProcessed => <<<'MD'
Hi {{customer_name}},

Your refund of **{{refund_amount}}** for order **{{order_id}}** has been processed. Depending on your bank, it can take a few business days to appear on your statement.

If you have any questions, contact {{support_email}}.
MD,
            K::OrderLink => <<<'MD'
Hi {{customer_name}},

Here is your secure link for order **{{order_id}}** ({{service_name}}):

{{button:order_link|Open your order}}

The link is personal — please don't share it. If you didn't request it, you can ignore this email.
MD,
            K::MagicLink => <<<'MD'
Hi,

Use the button below to sign in to your {{site_name}} account. The link expires in {{expires_minutes}} minutes and can be used once.

{{button:login_link|Sign in}}

If you didn't request this, you can safely ignore this email.
MD,
            K::NewsletterConfirm => <<<'MD'
Hi,

Please confirm that you'd like to receive helpful guides and application tips from {{site_name}}.

{{button:confirm_link|Confirm subscription}}

No spam — just useful content. You can unsubscribe at any time.
MD,
            K::SupportReceived => <<<'MD'
Hi {{customer_name}},

Thanks for getting in touch — we've received your message and will reply as soon as possible.

Reference: {{order_id}}
MD,
            K::PartnerDashboardLink => <<<'MD'
Hi {{partner_name}},

Here is your private link to your {{site_name}} referral dashboard. It shows how many people your link brought, the orders they paid for and the commission you have earned.

{{button:dashboard_link|Open your dashboard}}

Your referral code is **{{referral_code}}**. Share this link — whichever page people open, your code stays with them:

{{share_link}}

The dashboard link is personal: anyone who has it can see your referral figures, so please don't share it. If you didn't ask for this email, you can ignore it.
MD,
            K::PartnerApplicationConfirm => <<<'MD'
Hi {{applicant_name}},

Thanks for applying to become a {{site_name}} partner. Please confirm your email address so we can review your application:

{{button:confirm_link|Confirm my application}}

The link works for 48 hours. If you didn't apply, you can ignore this email and nothing will happen.
MD,
            K::PartnerApplicationReceived => <<<'MD'
Hi {{applicant_name}},

Thanks for confirming. Your application to become a {{site_name}} partner is now with our team, and we'll email you once we've reviewed it.

Questions in the meantime? Contact {{support_email}}.
MD,
            K::PartnerApplicationApproved => <<<'MD'
Hi {{partner_name}},

**Welcome — your application has been approved.** Your referral code is **{{referral_code}}** and your commission is **{{commission_rate}}** of what the people you refer pay.

Share this link. Whichever page people open, your code stays with them:

{{share_link}}

Your private dashboard shows your visits, referred orders and commission:

{{button:dashboard_link|Open your dashboard}}

**Before your first payout,** add your mobile money or bank details on the dashboard. We'll email you a link to confirm them, and only confirmed details are used.

The dashboard link is personal, so please don't share it.
MD,
            K::PartnerApplicationRejected => <<<'MD'
Hi {{applicant_name}},

Thank you for your interest in the {{site_name}} partner programme. After reviewing your application, we're not able to accept it at this time.

{{section:rejection_reason|Our note}}

If you have questions, contact {{support_email}}.
MD,
            K::PartnerPayoutRequested => <<<'MD'
Hi {{partner_name}},

We've received your payout request for **{{payout_amount}}**, to be paid to {{payment_method}}. We'll email you again when it has been sent.

{{button:dashboard_link|View your dashboard}}

If you didn't request this payout, please contact {{support_email}} straight away.
MD,
            K::PartnerPayoutPaid => <<<'MD'
Hi {{partner_name}},

**Your payout of {{payout_amount}} has been sent** to {{payment_method}}.

Payment reference: **{{payout_reference}}**

{{button:dashboard_link|View your dashboard}}

Depending on your provider, it can take a little while to appear. Questions? Contact {{support_email}}.
MD,
            K::PartnerPayoutRejected => <<<'MD'
Hi {{partner_name}},

We couldn't pay your payout request for **{{payout_amount}}**, so the amount is back in your available balance.

{{section:rejection_reason|The reason}}

{{button:dashboard_link|View your dashboard}}

If you have questions, contact {{support_email}}.
MD,
            K::PartnerPaymentDetailsConfirm => <<<'MD'
Hi {{partner_name}},

Someone asked to set these payment details for your {{site_name}} partner payouts:

**{{payment_method}}**

They are used only after you confirm them:

{{button:confirm_link|Review and confirm}}

The link works for 48 hours. **If you didn't ask for this, don't confirm.** Your current details stay as they are; please let us know at {{support_email}}.
MD,
        };
    }
}
