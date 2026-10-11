<?php

namespace Database\Seeders;

use App\Models\ArticleCategory;
use App\Models\Faq;
use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * CMS content: page sections, FAQs, article categories and starter legal
 * pages. Idempotent; never overwrites administrator edits.
 */
class ContentSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->pages() as $slug => $page) {
            Page::query()->firstOrCreate(['slug' => $slug], $page + ['is_published' => true, 'published_at' => now()]);
        }

        if (! Faq::query()->whereIn('scope', ['general', 'home', 'resources'])->exists()) {
            foreach ($this->faqs() as $position => [$scope, $question, $answer]) {
                Faq::query()->create(compact('scope', 'question', 'answer') + ['display_order' => $position, 'is_published' => true]);
            }
        }

        foreach ([
            ['Personal Statement', 'personal-statement', 'user'],
            ['SOP', 'sop', 'document'],
            ['Motivation Letter', 'motivation-letter', 'heart'],
            ['Scholarship', 'scholarship', 'graduation-cap'],
            ['Application Tips', 'application-tips', 'lightbulb'],
            ['CV & Resume', 'cv-resume', 'briefcase'],
            ['Study Abroad', 'study-abroad', 'globe'],
            ['General', 'general', 'book'],
        ] as $position => [$name, $slug, $icon]) {
            ArticleCategory::query()->firstOrCreate(['slug' => $slug], [
                'name' => $name,
                'icon' => $icon,
                'display_order' => $position,
                'is_active' => true,
            ]);
        }
    }

    private function pages(): array
    {
        return [
            'home' => [
                'title' => 'Home',
                'kind' => 'home',
                'seo_title' => 'Statementra: Personal Statement, SOP & CV Writing',
                'seo_description' => 'Personal statements, SOPs, motivation letters and scholarship essays, researched around your programme and written from your real experience.',
                'sections' => [
                    'hero' => [
                        'title' => 'Your story deserves more than a generic essay.',
                        'text' => 'Statementra researches your programme, institution and requirements before crafting a personalized application document that helps you stand out.',
                        'primary_cta' => 'Start Your Application',
                        'secondary_cta' => 'See How It Works',
                        'image' => 'images/home/hero.jpg',
                        'image_alt' => 'A student thinking about her application at her desk',
                        'image_caption' => "Better research.\nStronger writing.\nGreater opportunities.",
                        'trust_note' => 'No account required. Secure payment. Delivered by email.',
                    ],
                    'services' => [
                        'eyebrow' => 'Our services',
                        'title' => 'Application documents for every goal.',
                        'text' => 'Choose the service that fits your needs. All documents are customized, well-researched and professionally written.',
                    ],
                    'how_it_works' => [
                        'eyebrow' => 'How it works',
                        'title' => 'A simple process, a powerful result.',
                        'text' => "You focus on your goals. We'll handle the research, writing and formatting.",
                        'steps' => [
                            ['icon' => 'user', 'title' => 'Tell us about yourself', 'text' => 'Share your background and application details.'],
                            ['icon' => 'search', 'title' => 'We research', 'text' => 'We study your programme, institution and requirements.'],
                            ['icon' => 'document', 'title' => 'We write & refine', 'text' => 'Your document is developed, reviewed and professionally formatted.'],
                            ['icon' => 'mail', 'title' => 'Receive your documents', 'text' => 'PDF + editable Word document delivered to your email.'],
                        ],
                    ],
                    'research' => [
                        'eyebrow' => 'Our research process',
                        'title' => 'We go beyond the basics.',
                        'text' => "We don't just write. We research, verify and refine — so your document is accurate, relevant and tailored to your goals.",
                        'image' => 'images/home/research.jpg',
                        'image_alt' => 'A laptop and a cup of coffee on a desk',
                        'image_caption' => "Real research.\nVerified information.\nBetter results.",
                        'points' => ['Programme research', 'Institution research', 'Requirements verification', 'Personal background analysis', 'Writing & refinement', 'Final quality review'],
                    ],
                    'offer' => [
                        'eyebrow' => 'Special offer',
                        'title' => 'Get the support you need, at the right price.',
                        'text' => 'Take advantage of our limited-time offer and get your application document at a discounted rate.',
                        'service_slug' => 'personal-statement',
                        'tagline' => 'Perfect for university and college applications.',
                    ],
                    'testimonials' => [
                        'eyebrow' => 'What our clients say',
                        'title' => 'Ready to support you worldwide.',
                        'text' => 'Applicants around the world trust Statementra with their most important documents.',
                    ],
                    'resources' => [
                        'eyebrow' => 'Resources',
                        'title' => 'Guides & Writing Resources',
                        'text' => 'Helpful guides and tips to make your application stronger.',
                    ],
                    'faq' => [
                        'eyebrow' => 'FAQ',
                        'title' => 'Common Questions',
                        'text' => 'Find answers to the most frequently asked questions.',
                    ],
                    'cta' => [
                        'title' => 'Ready to tell your story?',
                        'text' => 'Take the first step towards your future. Your application starts here.',
                        'button' => 'Start Your Application',
                        'image' => 'images/home/cta.jpg',
                    ],
                ],
            ],
            'services' => [
                'title' => 'Services',
                'kind' => 'landing',
                'seo_title' => 'Application Writing Services: Statements, Essays & CVs',
                'seo_description' => 'Choose the application document you need. Every service includes programme research, personalized writing, fact-checking and professional formatting.',
                'sections' => [
                    'hero' => [
                        'eyebrow' => 'Our services',
                        'title' => 'Choose the service that fits your goal.',
                        'text' => "Every application is unique. Select the service you need, and we'll handle the research, writing and formatting — so you can focus on your next step.",
                        'image' => 'images/services/hero.jpg',
                        'image_alt' => 'Books and a laptop on a desk by a window',
                        'image_caption' => "Better research.\nStronger writing.\nGreater opportunities.",
                    ],
                    'list' => [
                        'eyebrow' => 'Available services',
                        'title' => 'What do you need help with?',
                        'text' => 'Each service is tailored to your specific goals, with personalized research, professional writing and proper formatting.',
                    ],
                    'why' => [
                        'eyebrow' => 'Why Statementra',
                        'title' => "More than just writing.\nIt's a complete application support service.",
                        'text' => 'We go beyond generic content. We research your programme, institution and requirements, analyze your background and create a personalized document that truly represents you.',
                        'button' => 'Learn More',
                        'features' => [
                            ['icon' => 'graduation-cap', 'title' => 'Deep Research', 'text' => 'We study your programme, institution and industry.'],
                            ['icon' => 'sparkle', 'title' => 'Personalized Approach', 'text' => 'Your unique background, voice and goals.'],
                            ['icon' => 'shield', 'title' => 'Factual Verification', 'text' => 'We check all key information for accuracy.'],
                            ['icon' => 'document', 'title' => 'Professional Formatting', 'text' => 'Meets country and institution requirements.'],
                        ],
                    ],
                ],
            ],
            'resources' => [
                'title' => 'Resources',
                'kind' => 'landing',
                'seo_title' => 'Application Writing Guides & Resources',
                'seo_description' => 'Free guides to writing personal statements, statements of purpose, motivation letters and scholarship essays — plus practical application tips.',
                'sections' => [
                    'hero' => [
                        'eyebrow' => 'Resources',
                        'title' => 'Guides, tips and resources to help you succeed.',
                        'text' => 'Access expert advice, step-by-step guides and practical tips to help you craft stronger application documents and move closer to your goals.',
                        'image' => 'images/resources/hero.jpg',
                        'image_alt' => 'A stack of books titled Higher Education, Career Growth and Global Opportunities beside a laptop',
                    ],
                    'featured' => ['eyebrow' => 'Featured guides', 'title' => 'Popular resources'],
                    'newsletter' => [
                        'eyebrow' => 'Stay informed',
                        'title' => 'Get helpful tips and updates.',
                        'text' => 'Subscribe to our newsletter for the latest guides, application tips and exclusive resources.',
                        'note' => "No spam.\nJust useful content.",
                    ],
                    'faq' => [
                        'eyebrow' => 'Frequently asked questions',
                        'title' => 'Still have questions?',
                        'text' => 'Find quick answers to common questions about our resources, services and application process.',
                    ],
                    'cta' => [
                        'title' => 'Ready to tell your story?',
                        'text' => 'Let Statementra help you craft a personalized application document that opens doors.',
                        'button' => 'Start Your Application',
                        'image' => 'images/resources/cta.jpg',
                    ],
                ],
            ],
            'how-it-works' => [
                'title' => 'How It Works',
                'kind' => 'landing',
                'seo_title' => 'How Statementra Works: Research, Writing and Delivery',
                'seo_description' => 'Tell us what you\'re applying for and pay securely. We research, write, fact-check and format your document, then email it to you in about 10–15 minutes.',
                'sections' => [
                    'hero' => [
                        'eyebrow' => 'How it works',
                        'title' => "Simple for you.\nThorough behind the scenes.",
                        'text' => 'You tell us about yourself and your application. We do the research, writing, fact-checking and formatting — then email you a finished document.',
                    ],
                    'details' => [
                        ['title' => 'Information extraction', 'text' => 'We read your answers and any documents you upload — CV, transcripts, programme requirements — and build a structured profile of your real experience. Nothing is invented.'],
                        ['title' => 'Programme & institution research', 'text' => 'We research your programme on official university, department and admissions pages: modules, research areas, structure and what the institution asks applicants to show.'],
                        ['title' => 'Source verification', 'text' => 'Every important external fact is checked against its source. Unverified claims are left out, and official sources always win when information conflicts.'],
                        ['title' => 'Requirements & formatting', 'text' => "We check word and character limits, required sections and the destination country's conventions — British or American English, page size, structure — before we write."],
                        ['title' => 'Narrative & writing', 'text' => 'We plan a narrative from your own experiences, write the document, then run a separate editorial pass for natural, specific, human writing.'],
                        ['title' => 'Quality & factual review', 'text' => 'Each document is scored for personalization, specificity, prompt adherence and accuracy. Anything below our threshold goes back for refinement.'],
                        ['title' => 'Delivery', 'text' => 'You receive a submission-ready PDF and an editable Word document by email, plus a secure link to view, download or request a revision.'],
                    ],
                ],
            ],
            'about' => [
                'title' => 'About Statementra',
                'kind' => 'standard',
                'excerpt' => 'Your story. Researched. Written. Refined.',
                'seo_title' => 'About Statementra: Researched, Personal Application Writing',
                'seo_description' => 'Statementra combines programme research, careful verification and professional writing to produce personalized application documents.',
                'body' => <<<'MD'
Statementra is a premium application-writing service. We help applicants turn their real experience into clear, specific, well-researched application documents — personal statements, statements of purpose, motivation letters, scholarship essays and more.

## Simple for you, sophisticated behind the scenes

Applying is stressful enough. With Statementra you tell us what you're applying for, share your background (a CV is often enough), pay securely and get on with your day. Behind the scenes our system researches your programme and institution on official sources, verifies what it finds, analyses your background, plans a narrative, writes, edits, fact-checks and formats your document to the requirements of the country and institution you're applying to.

## What we believe

- **Your story is the point.** We write from your real experiences and goals. We never invent achievements, experiences or motivations.
- **Specific beats generic.** Admissions readers notice when a document could have been written for any programme. Ours are built around the one you're applying to.
- **Accuracy matters.** We only use information about programmes and institutions that we can verify on authoritative sources.
- **Formatting is part of the service.** Word limits, structure, language conventions and file formats are checked before delivery.

## What we don't promise

We provide professional writing assistance. We don't promise admission or scholarships — no honest service can — and we don't claim our documents are "undetectable". You should review your document, make sure it reflects you, and follow your institution's policies on application assistance.
MD,
            ],
            'faq' => [
                'title' => 'Frequently Asked Questions',
                'kind' => 'landing',
                'seo_title' => 'FAQ — How Statementra Works, Delivery, Revisions and Privacy',
                'seo_description' => 'Answers to common questions about Statementra: how it works, delivery times, file formats, revisions, payments and how your information is protected.',
                'sections' => ['hero' => ['eyebrow' => 'FAQ', 'title' => 'Frequently asked questions', 'text' => "Can't find what you're looking for? Contact our support team and we'll help."]],
            ],
            'contact' => [
                'title' => 'Contact & Support',
                'kind' => 'landing',
                'seo_title' => 'Contact Statementra: Help With an Order or a Service',
                'seo_description' => 'Questions about an order or our services? Contact Statementra support — include your order reference if you have one.',
                'sections' => ['hero' => ['eyebrow' => 'Support', 'title' => 'How can we help?', 'text' => "Questions about an order or our services? Send us a message — include your order reference if you have one and we'll get back to you as soon as possible."]],
            ],
            'become-a-partner' => [
                'title' => 'Become a Partner',
                'kind' => 'landing',
                'seo_title' => 'Become a Statementra Partner',
                'seo_description' => 'Share Statementra with applicants you know and earn commission on what they pay. Apply to join the partner programme.',
                'sections' => [
                    'hero' => [
                        'eyebrow' => 'Partner programme',
                        'title' => 'Become a Statementra partner',
                        'text' => 'Know students and graduates applying for university places or scholarships? Share Statementra with them and earn a commission on what they pay.',
                    ],
                    'details' => [
                        ['title' => 'Apply', 'text' => 'Tell us about yourself and how you would share Statementra. Every application is reviewed by our team, and your commission rate is agreed with you when you are approved.'],
                        ['title' => 'Share your link', 'text' => 'Approved partners get a personal link and code. People you refer can also type your code when they pay.'],
                        ['title' => 'Track and get paid', 'text' => 'A private dashboard shows your visits, referred orders and commission. Request a payout to mobile money or a bank account once your balance reaches the minimum.'],
                    ],
                ],
            ],
            'privacy-policy' => [
                'title' => 'Privacy Policy',
                'kind' => 'legal',
                'seo_title' => 'Privacy Policy: How Statementra Handles Your Data',
                'seo_description' => 'How Statementra collects, uses, protects and deletes your personal data and uploaded documents, and the rights you have over them.',
                'body' => $this->privacyPolicy(),
            ],
            'terms' => [
                'title' => 'Terms of Service',
                'kind' => 'legal',
                'seo_title' => 'Terms of Service: Ordering, Delivery and Revisions',
                'seo_description' => 'The terms for ordering from Statementra: what we deliver, delivery times, revisions, payments and your responsibilities when you use the service.',
                'body' => $this->terms(),
            ],
            'refund-policy' => [
                'title' => 'Refund Policy',
                'kind' => 'legal',
                'seo_title' => 'Refund Policy: When and How Statementra Refunds',
                'seo_description' => 'When Statementra orders can be refunded, how to ask for a refund and how refunds are paid back to you through Paystack.',
                'body' => $this->refundPolicy(),
            ],
            'partner-terms' => [
                'title' => 'Partner Programme Terms',
                'kind' => 'legal',
                'seo_title' => 'Partner Programme Terms',
                'seo_description' => 'The terms of the Statementra partner programme: joining, referrals, commission, the holding period, payouts, tax and how partners may promote Statementra.',
                'body' => $this->partnerTerms(),
            ],
            'cookie-policy' => [
                'title' => 'Cookie Policy',
                'kind' => 'legal',
                'seo_title' => 'Cookie Policy: Cookies Used on Statementra',
                'seo_description' => 'Which cookies Statementra uses, why we use them and how you can control or delete them in your browser settings.',
                'body' => $this->cookiePolicy(),
            ],
        ];
    }

    /** @return list<array{0:string,1:string,2:string}> [scope, question, answer] */
    private function faqs(): array
    {
        return [
            ['home', 'How long does it take to receive my document?', 'Most documents are delivered within 10–15 minutes of payment. Occasionally an application needs extra research or a quick question to you; if so, we email you and keep you updated. You never need to keep the website open.'],
            ['home', 'What file formats will I receive?', 'You receive two files by email: a polished, submission-ready PDF and an editable Microsoft Word (.docx) document with exactly the same content. You can also download both from your secure order page.'],
            ['home', 'Do you offer revisions?', 'Yes. Every service includes a revision within the revision period shown at checkout. Just use the link in your delivery email and tell us what you would like to change.'],
            ['home', 'Is my information kept confidential?', 'Yes. Your information and documents are encrypted, stored privately and never shared publicly. We only use them to prepare your document, and we delete them after a retention period. See our Privacy Policy for details.'],
            ['home', 'Do you work with all countries and universities?', "Yes. We research your specific institution and programme and follow the conventions of the country you're applying to — for example British or American English, word limits and required structure."],
            ['general', 'How does Statementra work?', 'Choose a service, tell us about your application and background (uploading your CV saves time), and pay securely. Our system researches your programme and institution, verifies the information, writes and refines your document, checks it against the requirements and emails you the finished PDF and Word files.'],
            ['general', 'Do I need an account?', 'No. You can order without creating an account — everything is linked to your email address and a secure order link. You can optionally create an account to see your previous orders in one place.'],
            ['general', 'What information do I need?', "Just the basics: what you're applying for (institution, programme, country) and a little about your background and goals. If you have a CV, upload it — we'll extract your education, experience and achievements so you don't have to type them."],
            ['general', 'Can I upload my CV?', 'Yes. You can upload your CV, transcripts, programme requirements, a previous statement or other documents (PDF, Word, TXT, JPG or PNG, up to 10 MB each).'],
            ['general', 'How does payment work?', 'Payments are processed securely by Paystack. We never see or store your card details. Your order starts as soon as your payment is confirmed.'],
            ['general', 'What happens if I provide incomplete information?', "We never invent facts about you. If something essential is missing, we'll email you a short question with a secure link and continue as soon as you answer."],
            ['general', 'Can I use Statementra for multiple applications?', 'Yes — each application is a separate order, so every document is researched and written for that specific programme. Programme-specific documents are always stronger than one generic essay sent everywhere.'],
            ['general', 'Do you guarantee admission?', 'No. Nobody can honestly guarantee admission or a scholarship. We provide professional, researched writing assistance to help you present your real story as clearly and persuasively as possible.'],
            ['resources', 'Are the guides free?', 'Yes. All of our guides and articles are free to read.'],
            ['resources', 'Can I use these resources for multiple applications?', 'Absolutely. The guides explain principles that apply to most applications — just remember to tailor every document to the specific programme.'],
            ['resources', 'Do you provide personalized advice?', 'Our guides are general. For a document researched and written around your own background and programme, choose one of our services.'],
            ['resources', 'How often is the content updated?', 'We review our guides regularly, especially when application systems change their requirements.'],
            ['resources', 'Can I suggest a topic for a new guide?', "Yes — send us your idea through the contact page. We'd love to hear what would help you."],
        ];
    }

    private function privacyPolicy(): string
    {
        return <<<'MD'
_Last updated: {{date}}. This is a starter policy; review it with your legal adviser before launch._

Statementra ("we", "us") provides personalized application-writing services. This policy explains what personal information we collect, how we use it and the choices you have.

## Information we collect

- **Order information:** your name, email address, optional phone number, the institution, programme and country you are applying to, your answers to our questions and any documents you upload (for example a CV, transcript or programme requirements).
- **Payment information:** payments are processed by Paystack. We receive confirmation of payment, the amount and a transaction reference. We do not receive or store your full card details.
- **Technical information:** basic request information (such as IP address and browser type) used for security, fraud prevention and rate limiting. Our analytics are cookieless and do not store raw IP addresses.
- **Communications:** messages you send to our support team and feedback you choose to give.
- **Referral information:** if you reach us through a partner's referral link, or type a partner's code when you pay, we record which partner referred your order. Partners only see that an order was placed, its amount and their commission, never your name, contact details, order reference or documents.
- **Partner programme information:** if you apply to become a partner, your name, email address, phone number, city and country, and your answers on the application. Once you are approved, the mobile money or bank details you give us for payouts, and a record of your referrals, commission and payouts.

## How we use your information

- To prepare, deliver and revise your document (including researching your programme and institution).
- To process payments and prevent fraud and abuse.
- To send transactional emails about your order. We only send marketing emails if you subscribe to our newsletter.
- To improve our service using aggregated quality measures and the feedback you provide.
- To credit the partner who referred you, and to run our partner programme: reviewing applications, calculating commission and paying partners.

## AI processing and service providers

Your documents are prepared with the help of AI models provided by OpenAI. We send only the information needed to prepare your document, we instruct the models to treat your documents as data, and we configure the service not to retain your content for training. We also use trusted providers for payments (Paystack), email delivery, hosting and file storage. They process data only on our instructions.

## Referrals and our partner programme

When you follow a partner's referral link, we remember the referral for your current visit so that the partner is credited if you order. We do this in our legitimate interest in running the programme, and it never changes your price. We remember a referral for longer (30 days) only if you agree, using the cookie described in our [Cookie Policy](/cookie-policy), and never when your browser sends a Global Privacy Control signal. You can also type a partner's code yourself when you pay.

We use partners' information to consider their application, to run the partnership, to pay them and to meet our accounting and tax obligations. Payout details are encrypted, are used only after the partner has confirmed them from their email, and can be seen only by the staff who handle payouts, with each view logged. Partner payouts are sent by mobile money or bank transfer.

## How we protect your information

Uploaded files and generated documents are encrypted and kept in private storage. Access to your order requires a secure, expiring link sent to your email (or your optional account). Our staff access customer data only when necessary to provide support, and access is logged.

## Retention

We keep your uploaded documents and generated documents for {{retention_days}} days after delivery so that you can download them and request revisions, after which they are deleted. Order and payment records are kept as long as required for accounting and legal purposes.

Partner programme records: applications that are never confirmed are deleted after 7 days, rejected applications after 180 days and approved applications after one year. Commission and payout records are kept for six years for accounting and tax purposes. After that, payouts no longer show the account they were paid to, and a partner whose partnership ended six years earlier has their personal information erased. Referral visit counts contain no personal information.

## Your rights

Depending on where you live, you may have the right to access, correct, delete or export your personal information, or to object to certain processing. Contact us at the address below and we will respond within the time required by law.

## Contact

Questions about privacy? Email {{support_email}}.
MD;
    }

    private function terms(): string
    {
        return <<<'MD'
_Last updated: {{date}}. This is a starter agreement; review it with your legal adviser before launch._

By placing an order with Statementra you agree to these terms.

## The service

Statementra prepares personalized application documents (such as personal statements, statements of purpose, motivation letters and essays) based on the information you provide and on research into your chosen programme and institution. Estimated delivery times are estimates, not guarantees.

## Your responsibilities

- Provide accurate, truthful information. We do not invent experiences, achievements or qualifications, and you must not ask us to.
- Review your document carefully before submitting it, and make sure it reflects your own experiences and views.
- Follow the rules of the institutions and programmes you apply to, including any rules about assistance with application materials.

## No guarantee of outcome

We provide professional writing assistance. We do not guarantee admission, scholarships, interviews or any other outcome, and we do not claim that documents are undetectable by any tool.

## Payment

Prices are shown at checkout and charged in the currency displayed. Payment is processed by Paystack. Your order begins once payment is confirmed.

## Revisions

Each service includes the number of revisions and the revision period shown on the service page and at checkout. Revisions are changes to the delivered document; a different programme, institution or document type is a new order.

## Ownership

Once your order is paid, you may use the delivered document for your applications. You remain responsible for how you use it.

## Refunds

Refunds are handled under our Refund Policy.

## Liability

To the extent permitted by law, our total liability for any order is limited to the amount you paid for it.

## Contact

Questions about these terms? Email {{support_email}}.
MD;
    }

    private function refundPolicy(): string
    {
        return <<<'MD'
_Last updated: {{date}}. Administrators can edit this policy at any time in the admin panel._

We want you to be happy with your document. This policy explains when refunds are available.

## Before delivery

If you cancel before we begin processing your order, you are entitled to a full refund. Because processing usually starts immediately after payment, contact us as soon as possible.

## If we cannot deliver

If we are unable to deliver your document — for example because of a technical failure we cannot resolve — we will refund you in full.

## After delivery

Delivered documents are personalized digital products. If your document does not meet the description of the service, please request a revision first; most issues are resolved this way. If a revision does not resolve a genuine problem, contact us and we will review your request for a full or partial refund.

## How refunds are paid

Approved refunds are returned to your original payment method through Paystack. Depending on your bank, it may take several business days to appear.

## How to request a refund

Email {{support_email}} with your order reference and the reason for your request.
MD;
    }

    private function partnerTerms(): string
    {
        return <<<'MD'
_Last updated: {{date}}._

These terms apply to the {{site_name}} partner programme. By applying to the programme, you agree to them. "We" means {{site_name}}; "you" means the partner.

## Joining

- Apply on our [Become a partner](/become-a-partner) page and confirm your email address. We review every application and may decline one without giving a reason.
- You must be at least 18 years old and apply in your own name. Each person may have one partner account.
- When we approve you, we email you your referral link and code, and a private link to your partner dashboard.

## Your link, code and referrals

- Share your link (any page of our website with `?ref=` and your code at the end) or your code. Customers can also type your code when they pay.
- An order is credited to you when the customer followed your link during the visit in which they ordered, followed it in the previous 30 days and agreed to be remembered, or typed your code when paying. If a customer follows several partners' links, the last one counts; a code typed at payment takes priority.
- Referral codes never change the customer's price.
- No commission is earned on orders you place or pay for yourself, or on orders that use your email address.

## Commission

- Your commission rate is agreed with you when you are approved and is shown on your dashboard. A new rate applies only to payments made after we tell you about it.
- Commission is a percentage of what the customer actually pays for a referred order, after any discount, including paid revisions of that order. It is calculated before payment-provider fees and rounded down to the nearest pesewa.
- If an order is refunded, in full or in part, the commission on the refunded amount is deducted from your balance, even if it had already become available. If your balance falls below zero, the difference is taken from future commission.

## Holding period and payouts

- New commission is pending for 14 days (our refund and revision window), then becomes available.
- When your available balance reaches GH₵100, you can request a payout from your dashboard. A request covers your whole available balance; you can also leave your balance to grow.
- Payouts are sent to the mobile money or bank account in your name that you have added on your dashboard and confirmed from your email. We may hold a payout while we check a recent change of payment details.
- We aim to pay within 7 working days of your request. We pay the cost of sending the payout; any charge your bank or network makes for receiving it is yours.
- We may reject a request and tell you why, for example when payment details cannot be verified. The amount then returns to your available balance.

## Tax

You are responsible for declaring your commission and paying any tax on it. Where the law requires us to deduct tax from a payment to you, we will deduct it and give you a record of the deduction.

## How you may promote us

- Be honest. Never promise admission, a scholarship, a job, a grade or any other outcome, and never say that we guarantee results.
- Do not present yourself as {{site_name}} or as our staff, and do not use our name or logo in a way that suggests you are us.
- Do not send unsolicited bulk messages or add people to groups without their permission. Follow the rules of each platform you use and the law.
- Do not bid on our name in search adverts, place orders that are not genuine, or publish your code on coupon or cashback websites without our written permission.
- Do not send us other people's personal information.

## Your dashboard link

Keep your dashboard link private: anyone who has it can see your dashboard. If you lose it, request it again on our [partner page](/partner). We can also replace it for you.

## Ending the partnership

- You can leave the programme at any time by telling us. We can end the partnership at any time with notice, or immediately if you break these terms.
- When the partnership ends, we pay your available balance, even if it is below the minimum, once any pending commission has passed its holding period. Commission is not earned on payments made after the partnership ends.
- We may withhold or reverse commission connected to fraud, self-referral or a breach of these terms.

## Your information

We handle your information as described in our [Privacy Policy](/privacy-policy).

## Changes to these terms

We may change these terms. We will email you at least 14 days before a change takes effect. If you stay in the programme after that, the new terms apply.

## Law and contact

These terms are governed by the laws of Ghana. Questions about the programme? Email {{support_email}}.
MD;
    }

    private function cookiePolicy(): string
    {
        return <<<'MD'
_Last updated: {{date}}._

Statementra uses the cookies needed to run the website securely, and one optional cookie that is set only if you agree. We do not use advertising or third-party tracking cookies, and our analytics are cookieless.

| Cookie | Purpose | Duration |
| --- | --- | --- |
| Session cookie | Keeps your session (for example, your place in checkout) | Until you close the browser or after inactivity |
| XSRF-TOKEN | Protects forms against cross-site request forgery | Session |
| st_checkout | Links the files you upload to your application before payment | Up to 3 days |
| st_ref (optional) | Remembers the partner who referred you, so that they are credited if you order on a later visit. Set only if you choose "Remember" when we ask | 30 days |

The first three cookies are strictly necessary, so they do not require consent. You can block cookies in your browser, but checkout may not work without them.

**The referral cookie.** If you arrive through a partner's referral link, we ask whether to remember the referral for 30 days. If you choose "No thanks", or your browser sends a Global Privacy Control signal, we remember it only for your current visit and set no cookie. To withdraw your consent, delete the st_ref cookie in your browser settings; the partner will then not be credited for later orders.
MD;
    }
}
