<?php

namespace App\Filament\Support\Catalogue;

use App\Enums\EmailTemplateKey;
use App\Support\Money;
use App\Support\Settings;

/** Realistic sample values for previewing and test-sending email templates. */
final class SampleEmailVariables
{
    /** @return array<string, mixed> */
    public static function for(EmailTemplateKey $key): array
    {
        $currency = Settings::currency();
        $all = [
            'customer_name' => 'Ama',
            'order_id' => 'ST-7KQ3-M9XD',
            'service_name' => 'Personal Statement',
            'institution' => 'University of Edinburgh',
            'programme' => 'MSc Data Science',
            'order_link' => url('/orders/sample-preview'),
            'amount_paid' => Money::format(8900, $currency),
            'delivery_time' => 'about '.Settings::formatMinutesRange((int) Settings::get('orders.delivery_min_minutes', 20), (int) Settings::get('orders.delivery_max_minutes', 30)),
            'questions' => [
                'What first drew you to data science, and when?',
                'Which modules of the programme interest you most, and why?',
            ],
            'document_link' => url('/orders/sample-preview/document'),
            'pdf_link' => url('/orders/sample-preview/document.pdf'),
            'docx_link' => url('/orders/sample-preview/document.docx'),
            'revisions_remaining' => '1',
            'revision_deadline' => now()->addDays(14)->format('j F Y'),
            'revision_number' => '1',
            'refund_amount' => Money::format(8900, $currency),
            'login_link' => url('/account/sign-in/sample-preview'),
            'expires_minutes' => '30',
            'confirm_link' => url('/newsletter/confirm/sample-preview'),
        ];

        return array_intersect_key($all, array_flip($key->variables()));
    }

    /** @return list<string> every variable a template may use, globals included */
    public static function names(EmailTemplateKey $key): array
    {
        return [...$key->variables(), 'site_name', 'support_email', 'site_url'];
    }
}
