<?php

namespace Database\Seeders;

use App\Domain\Email\DefaultEmailTemplates;
use App\Enums\EmailTemplateKey;
use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

/** Seeds the editable email templates (never overwrites admin edits). */
class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (EmailTemplateKey::cases() as $key) {
            EmailTemplate::query()->firstOrCreate(['key' => $key->value], [
                'name' => $key->getLabel(),
                'description' => $key->isEssential() ? 'Essential email (cannot be disabled).' : 'Optional email.',
                'subject' => DefaultEmailTemplates::subject($key),
                'body' => DefaultEmailTemplates::body($key),
                'variables' => array_merge($key->variables(), ['site_name', 'support_email', 'site_url']),
                'is_active' => true,
            ]);
        }
    }
}
