<?php

use App\Enums\AdminRole;
use App\Enums\EmailTemplateKey;
use App\Filament\Resources\EmailTemplates\EmailTemplateResource;
use App\Filament\Resources\EmailTemplates\Pages\EditEmailTemplate;
use App\Filament\Resources\EmailTemplates\Pages\ListEmailTemplates;
use App\Models\AuditLog;
use App\Models\EmailMessage;
use App\Models\EmailTemplate;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Mail::fake();
});

function template(EmailTemplateKey $key): EmailTemplate
{
    return EmailTemplate::query()->where('key', $key->value)->firstOrFail();
}

it('lists every template and offers no create or delete', function () {
    actingAsAdmin(AdminRole::Content);
    (new EmailTemplateSeeder)->run();

    $this->get(EmailTemplateResource::getUrl('index'))->assertOk()->assertSee('Document ready')->assertSee('Essential');
    $this->get(EmailTemplateResource::getUrl('edit', ['record' => template(EmailTemplateKey::DocumentReady)]))
        ->assertOk()
        ->assertSee('{{document_link}}')
        ->assertSee('{{button:');

    expect(EmailTemplateResource::canCreate())->toBeFalse()
        ->and(EmailTemplateResource::canDelete(template(EmailTemplateKey::DocumentReady)))->toBeFalse()
        ->and(array_keys(EmailTemplateResource::getPages()))->toBe(['index', 'edit']);
});

it('never deactivates essential templates but can turn optional ones off', function () {
    actingAsAdmin(AdminRole::Content);
    (new EmailTemplateSeeder)->run();
    $essential = template(EmailTemplateKey::PaymentReceived);
    $optional = template(EmailTemplateKey::ProcessingDelay);

    Livewire::test(EditEmailTemplate::class, ['record' => $essential->getRouteKey()])
        ->assertFormFieldDisabled('is_active')
        ->fillForm(['is_active' => false, 'subject' => 'Payment confirmed for {{service_name}}'])
        ->call('save')
        ->assertHasNoFormErrors();

    $essential->refresh();
    expect($essential->is_active)->toBeTrue()
        ->and($essential->subject)->toBe('Payment confirmed for {{service_name}}');

    Livewire::test(EditEmailTemplate::class, ['record' => $optional->getRouteKey()])
        ->assertFormFieldEnabled('is_active')
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($optional->refresh()->is_active)->toBeFalse();

    $audit = AuditLog::query()->where('action', 'email_template.updated')->where('target_id', (string) $optional->id)->sole();
    expect($audit->before['is_active'])->toBeTrue()->and($audit->after['is_active'])->toBeFalse();
});

it('previews unsaved changes with sample data', function () {
    actingAsAdmin(AdminRole::Content);
    (new EmailTemplateSeeder)->run();
    $template = template(EmailTemplateKey::DocumentReady);

    Livewire::test(EditEmailTemplate::class, ['record' => $template->getRouteKey()])
        ->fillForm(['subject' => 'Ready, {{customer_name}}!', 'body' => "Hello **{{customer_name}}**\n\n{{button:document_link|Open your document}}"])
        ->mountAction('preview')
        ->assertMountedActionModalSee(['Ready, Ama!', 'Open your document', 'sandbox'], escape: false);

    expect($template->refresh()->subject)->not->toBe('Ready, {{customer_name}}!');
});

it('sends a test email of the saved template to the current admin and audits it', function () {
    $admin = actingAsAdmin(AdminRole::Content);
    (new EmailTemplateSeeder)->run();
    $template = template(EmailTemplateKey::DocumentReady);

    Livewire::test(EditEmailTemplate::class, ['record' => $template->getRouteKey()])
        ->callAction('sendTest')
        ->assertNotified('Test email sent');

    $email = EmailMessage::query()->where('to_email', $admin->email)->sole();
    expect($email->template_key)->toBe('document_ready')
        ->and($email->meta['purpose'])->toBe('admin_test')
        ->and($email->subject)->toBe('Your Statementra document is ready');

    expect(AuditLog::query()->where('action', 'email_template.test_sent')->where('admin_user_id', $admin->id)->exists())->toBeTrue();
});

it('restores missing templates from the defaults', function () {
    actingAsAdmin(AdminRole::Content);
    (new EmailTemplateSeeder)->run();
    template(EmailTemplateKey::MagicLink)->delete();

    Livewire::test(ListEmailTemplates::class)
        ->assertActionVisible('restoreMissing')
        ->callAction('restoreMissing');

    expect(EmailTemplate::query()->where('key', EmailTemplateKey::MagicLink->value)->exists())->toBeTrue()
        ->and(EmailTemplate::query()->count())->toBe(count(EmailTemplateKey::cases()));
});

it('is not available without email_templates.manage', function () {
    actingAsAdmin(AdminRole::Finance);

    $this->withoutVite()->get(EmailTemplateResource::getUrl('index'))->assertForbidden();
});
