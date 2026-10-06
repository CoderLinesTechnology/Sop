<?php

namespace App\Filament\Resources\EmailTemplates\Pages;

use App\Domain\Email\DefaultEmailTemplates;
use App\Domain\Email\TemplateRenderer;
use App\Domain\Email\TransactionalMailer;
use App\Filament\Resources\EmailTemplates\EmailTemplateResource;
use App\Filament\Resources\EmailTemplates\Schemas\EmailTemplateForm;
use App\Filament\Support\Catalogue\AdminAccess;
use App\Filament\Support\Catalogue\AuditDiff;
use App\Filament\Support\Catalogue\SampleEmailVariables;
use App\Models\EmailTemplate;
use App\Support\Audit;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * @property EmailTemplate $record
 */
class EditEmailTemplate extends EditRecord
{
    protected static string $resource = EmailTemplateResource::class;

    /** @var array<string, mixed> */
    protected array $auditBefore = [];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview')
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->modalHeading('Preview with sample data')
                ->modalDescription('Rendered from the current form, including unsaved changes.')
                ->modalWidth(Width::FourExtraLarge)
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn (): HtmlString => $this->renderPreview()),
            Action::make('sendTest')
                ->label('Send test email')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Send a test email')
                ->modalDescription(fn (): string => 'Sends the saved version of this template, with sample data, to '.AdminAccess::user()?->email.'. Save your changes first if you want to test them.')
                ->modalSubmitActionLabel('Send test')
                ->rateLimit(5)
                ->action(fn () => $this->sendTest()),
            Action::make('resetToDefault')
                ->label('Reset to default wording')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Replaces the subject and body with the built-in default wording. Your current wording is kept in the audit log.')
                ->action(function (): void {
                    $key = EmailTemplateForm::key($this->record);
                    if (! $key) {
                        return;
                    }

                    $before = AuditDiff::snapshot($this->record, ['subject', 'body']);
                    $this->record->forceFill([
                        'subject' => DefaultEmailTemplates::subject($key),
                        'body' => DefaultEmailTemplates::body($key),
                        'updated_by_admin_id' => AdminAccess::user()?->id,
                    ])->save();
                    Audit::log('email_template.reset', $this->record, $before, AuditDiff::snapshot($this->record, ['subject', 'body']));

                    $this->refreshFormData(['subject', 'body']);
                    Notification::make()->success()->title('Default wording restored')->send();
                }),
        ];
    }

    protected function beforeSave(): void
    {
        $this->auditBefore = AuditDiff::snapshot($this->record, ['name', 'description', 'subject', 'body', 'is_active']);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Essential emails can never be turned off, whatever the request says.
        if (EmailTemplateForm::isEssential($this->record)) {
            $data['is_active'] = true;
        }

        $data['updated_by_admin_id'] = AdminAccess::user()?->id;

        return $data;
    }

    protected function afterSave(): void
    {
        [$before, $after] = AuditDiff::changes($this->auditBefore, AuditDiff::snapshot($this->record, ['name', 'description', 'subject', 'body', 'is_active']));

        if ($after !== []) {
            Audit::log('email_template.updated', $this->record, $before, $after, ['key' => $this->record->key]);
        }
    }

    private function renderPreview(): HtmlString
    {
        $key = EmailTemplateForm::key($this->record);
        $state = $this->form->getRawState();

        $template = new EmailTemplate([
            'key' => $this->record->key,
            'subject' => (string) ($state['subject'] ?? $this->record->subject),
            'body' => (string) ($state['body'] ?? $this->record->body),
            'is_active' => true,
        ]);

        try {
            $rendered = app(TemplateRenderer::class)->render($template, $key ? SampleEmailVariables::for($key) : []);
        } catch (Throwable $e) {
            report($e);

            return new HtmlString('<p style="color: var(--danger-600);">The template could not be rendered. Check the Markdown and variables.</p>');
        }

        return new HtmlString(
            '<div style="display: grid; gap: .75rem;">'
            .'<div><span style="color: var(--gray-500);">Subject:</span> <strong>'.e($rendered['subject']).'</strong></div>'
            .'<iframe title="Email preview" sandbox="" srcdoc="'.e($rendered['html']).'" style="width: 100%; height: 65vh; border: 1px solid var(--gray-200); border-radius: .5rem; background: #fff;"></iframe>'
            .'<details><summary style="cursor: pointer; color: var(--gray-600);">Plain-text version</summary>'
            .'<pre style="white-space: pre-wrap; font-size: .8rem; margin-top: .5rem;">'.e($rendered['text']).'</pre></details>'
            .'</div>'
        );
    }

    private function sendTest(): void
    {
        $admin = AdminAccess::user();
        $key = EmailTemplateForm::key($this->record);

        if (! $admin || ! $key) {
            return;
        }

        $email = app(TransactionalMailer::class)->send(
            $key,
            $admin->email,
            SampleEmailVariables::for($key),
            meta: ['purpose' => 'admin_test', 'admin_user_id' => $admin->id],
        );

        if (! $email) {
            Notification::make()
                ->warning()
                ->title('Not sent')
                ->body('This optional email is turned off. Turn it on and save to send a test.')
                ->send();

            return;
        }

        Audit::log('email_template.test_sent', $this->record, null, null, ['key' => $key->value, 'to' => $admin->email, 'email_id' => $email->id]);

        Notification::make()
            ->success()
            ->title('Test email queued')
            ->body("It will arrive at {$admin->email} shortly.")
            ->send();
    }
}
