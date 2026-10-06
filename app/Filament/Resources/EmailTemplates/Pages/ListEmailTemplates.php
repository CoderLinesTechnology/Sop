<?php

namespace App\Filament\Resources\EmailTemplates\Pages;

use App\Domain\Email\DefaultEmailTemplates;
use App\Enums\EmailTemplateKey;
use App\Filament\Resources\EmailTemplates\EmailTemplateResource;
use App\Filament\Support\Catalogue\SampleEmailVariables;
use App\Models\EmailTemplate;
use App\Support\Audit;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListEmailTemplates extends ListRecords
{
    protected static string $resource = EmailTemplateResource::class;

    protected ?string $subheading = 'Transactional emails sent to customers. Edit the wording; the set of emails is fixed.';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('restoreMissing')
                ->label('Restore missing templates')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (): bool => self::missingKeys() !== [] && EmailTemplateResource::canViewAny())
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'Creates the default wording for: '.implode(', ', array_map(fn (EmailTemplateKey $key) => $key->getLabel(), self::missingKeys())).'.')
                ->action(function (): void {
                    foreach (self::missingKeys() as $key) {
                        $template = EmailTemplate::query()->create([
                            'key' => $key->value,
                            'name' => $key->getLabel(),
                            'description' => $key->isEssential() ? 'Essential email (cannot be disabled).' : 'Optional email.',
                            'subject' => DefaultEmailTemplates::subject($key),
                            'body' => DefaultEmailTemplates::body($key),
                            'variables' => SampleEmailVariables::names($key),
                            'is_active' => true,
                        ]);
                        Audit::log('email_template.restored', $template, null, ['key' => $key->value]);
                    }

                    Notification::make()->success()->title('Missing templates restored')->send();
                }),
        ];
    }

    /** @return list<EmailTemplateKey> */
    private static function missingKeys(): array
    {
        $existing = EmailTemplate::query()->pluck('key')->all();

        return array_values(array_filter(EmailTemplateKey::cases(), fn (EmailTemplateKey $key): bool => ! in_array($key->value, $existing, true)));
    }
}
