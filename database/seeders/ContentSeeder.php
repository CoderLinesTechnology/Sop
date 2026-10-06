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
                'seo_title' => 'Statementra — Personalized application documents, researched and written for you',
                'seo_description' => 'Personal statements, statements of purpose, motivation letters and scholarship essays researched around your programme and written around your real experience. Delivered by email in about 20–30 minutes.',
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
                'seo_title' => 'Services — Personal Statements, SOPs, Motivation Letters & Scholarship Essays',
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
                'seo_title' => 'How Statementra Works — Research, Writing, Verification, Delivery',
                'seo_description' => 'Tell us what you are applying for, share your background and pay securely. We research, write, verify and format your document and email it to you in about 20–30 minutes.',
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
                'seo_title' => 'About Statementra',
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
                'seo_title' => 'Contact Statementra Support',
                'seo_description' => 'Questions about an order or our services? Contact Statementra support — include your order reference if you have one.',
                'sections' => ['hero' => ['eyebrow' => 'Support', 'title' => 'How can we help?', 'text' => "Questions about an order or our services? Send us a message — include your order reference if you have one and we'll get back to you as soon as possible."]],
            ],
            'privacy-policy' => [
                'title' => 'Privacy Policy',
                'kind' => 'legal',
                'seo_title' => 'Privacy Policy',
                'body' => $this->privacyPolicy(),
            ],
            'terms' => [
                'title' => 'Terms of Service',
                'kind' => 'legal',
                'seo_title' => 'Terms of Service',
                'body' => $this->terms(),
            ],
            'refund-policy' => [
                'title' => 'Refund Policy',
                'kind' => 'legal',
                'seo_title' => 'Refund Policy',
                'body' => $this->refundPolicy(),
            ],
            'cookie-policy' => [
                'title' => 'Cookie Policy',
                'kind' => 'legal',
                'seo_title' => 'Cookie Policy',
                'body' => $this->cookiePolicy(),
            ],
        ];
    }

    /** @return list<array{0:string,1:string,2:string}> [scope, question, answer] */
    private function faqs(): array
    {
        return [
            ['home', 'How long does it take to receive my document?', 'Most documents are delivered within 20–30 minutes of payment. Occasionally an application needs extra research or a quick question to you; if so, we email you and keep you updated. You never need to keep the website open.'],
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

## How we use your information

- To prepare, deliver and revise your document (including researching your programme and institution).
- To process payments and prevent fraud and abuse.
- To send transactional emails about your order. We only send marketing emails if you subscribe to our newsletter.
- To improve our service using aggregated quality measures and the feedback you provide.

## AI processing and service providers

Your documents are prepared with the help of AI models provided by OpenAI. We send only the information needed to prepare your document, we instruct the models to treat your documents as data, and we configure the service not to retain your content for training. We also use trusted providers for payments (Paystack), email delivery, hosting and file storage. They process data only on our instructions.

## How we protect your information

Uploaded files and generated documents are encrypted and kept in private storage. Access to your order requires a secure, expiring link sent to your email (or your optional account). Our staff access customer data only when necessary to provide support, and access is logged.

## Retention

We keep your uploaded documents and generated documents for {{retention_days}} days after delivery so that you can download them and request revisions, after which they are deleted. Order and payment records are kept as long as required for accounting and legal purposes.

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

    private function cookiePolicy(): string
    {
        return <<<'MD'
_Last updated: {{date}}._

Statementra uses only the cookies needed to run the website securely. We do not use advertising or third-party tracking cookies, and our analytics are cookieless.

| Cookie | Purpose | Duration |
| --- | --- | --- |
| Session cookie | Keeps your session (for example, your place in checkout) | Until you close the browser or after inactivity |
| XSRF-TOKEN | Protects forms against cross-site request forgery | Session |
| st_checkout | Links the files you upload to your application before payment | Up to 3 days |

Because these cookies are strictly necessary, they do not require consent. You can block cookies in your browser, but checkout may not work without them.
MD;
    }
}
