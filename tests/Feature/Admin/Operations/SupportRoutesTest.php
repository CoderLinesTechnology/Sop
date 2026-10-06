<?php

use App\Enums\AdminRole;
use App\Enums\OrderStatus;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\EmailMessage;
use App\Models\Feedback;
use App\Models\NewsletterSubscriber;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Feature\Admin\Operations\Fixtures;

it('sends guests to the admin sign-in page', function () {
    $order = Fixtures::paidOrder();
    $file = Fixtures::uploadedFile($order);

    $this->get(route('admin.support.orders.files.download', ['order' => $order->public_id, 'file' => $file->uuid]))
        ->assertRedirect(filament()->getPanel('admin')->getLoginUrl());
});

it('requires multi-factor authentication to be set up', function () {
    (new RolesAndPermissionsSeeder)->run();
    $admin = AdminUser::factory()->withoutMfa()->create();
    $admin->assignRole(AdminRole::SuperAdmin->value);
    $this->actingAs($admin, 'admin');

    $order = Fixtures::paidOrder();
    $file = Fixtures::uploadedFile($order);

    $this->get(route('admin.support.orders.files.download', ['order' => $order->public_id, 'file' => $file->uuid]))
        ->assertForbidden();
});

it('streams a decrypted customer upload and audits the download', function () {
    $admin = actingAsAdmin(AdminRole::Operations);
    $order = Fixtures::paidOrder();
    $file = Fixtures::uploadedFile($order);

    $response = $this->get(route('admin.support.orders.files.download', ['order' => $order->public_id, 'file' => $file->uuid]));

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertDownload();
    expect($response->streamedContent())->toBe(Fixtures::PDF_CONTENTS)
        ->and($response->headers->get('Content-Disposition'))->not->toContain('"CV"')
        ->and(AuditLog::query()->where('action', 'order.file_downloaded')->where('admin_user_id', $admin->id)->count())->toBe(1);
});

it('never serves a file through another order', function () {
    actingAsAdmin(AdminRole::Operations);
    $order = Fixtures::paidOrder();
    $other = Fixtures::paidOrder();
    $file = Fixtures::uploadedFile($other);

    $this->get(route('admin.support.orders.files.download', ['order' => $order->public_id, 'file' => $file->uuid]))
        ->assertNotFound();
});

it('requires customers.view to download uploads', function () {
    actingAsAdmin(AdminRole::Finance);
    $order = Fixtures::paidOrder();
    $file = Fixtures::uploadedFile($order);

    $this->get(route('admin.support.orders.files.download', ['order' => $order->public_id, 'file' => $file->uuid]))
        ->assertForbidden();

    expect(AuditLog::query()->where('action', 'order.file_downloaded')->count())->toBe(0);
});

it('shows a document PDF inline and downloads the DOCX', function () {
    actingAsAdmin(AdminRole::Ai);
    $order = Fixtures::paidOrder(OrderStatus::Delivered);
    $version = Fixtures::documentVersion($order);

    $inline = $this->get(route('admin.support.orders.documents.show', ['order' => $order->public_id, 'documentVersion' => $version->uuid, 'format' => 'pdf', 'inline' => 1]));
    $inline->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($inline->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($inline->getContent())->toBe(Fixtures::PDF_CONTENTS);

    $docx = $this->get(route('admin.support.orders.documents.show', ['order' => $order->public_id, 'documentVersion' => $version->uuid, 'format' => 'docx']));
    $docx->assertOk()->assertDownload('Ada_Lovelace_Personal_Statement.docx');
    expect($docx->streamedContent())->toBe(Fixtures::DOCX_CONTENTS)
        ->and(AuditLog::query()->where('action', 'order.document_previewed')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'order.document_downloaded')->count())->toBe(1);
});

it('does not let finance download documents', function () {
    actingAsAdmin(AdminRole::Finance);
    $order = Fixtures::paidOrder(OrderStatus::Delivered);
    $version = Fixtures::documentVersion($order);

    $this->get(route('admin.support.orders.documents.show', ['order' => $order->public_id, 'documentVersion' => $version->uuid, 'format' => 'pdf']))
        ->assertForbidden();
});

it('previews email HTML in a sandbox', function () {
    actingAsAdmin(AdminRole::Operations);
    $order = Fixtures::paidOrder();
    $email = EmailMessage::query()->create([
        'order_id' => $order->id,
        'template_key' => 'order_link',
        'to_email' => $order->email,
        'subject' => 'Your order',
        'html_body' => '<p>Hello</p><script>alert(1)</script>',
        'status' => 'sent',
    ]);

    $response = $this->get(route('admin.support.emails.preview', ['email' => $email->uuid]));

    $response->assertOk();
    expect($response->headers->get('Content-Security-Policy'))->toContain('sandbox')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")
        ->and(AuditLog::query()->where('action', 'order.email_viewed')->count())->toBe(1);
});

it('exports newsletter subscribers as an audited CSV with formula injection neutralised', function () {
    $admin = actingAsAdmin(AdminRole::Content);
    NewsletterSubscriber::query()->create(['email' => 'ada@example.com', 'status' => 'confirmed', 'source' => '=HYPERLINK("x")', 'confirmed_at' => now()]);
    NewsletterSubscriber::query()->create(['email' => 'bob@example.com', 'status' => 'pending']);

    $response = $this->get(route('admin.support.exports.newsletter', ['status' => 'confirmed']));

    $response->assertOk()->assertDownload();
    $csv = $response->streamedContent();

    expect($csv)->toContain('ada@example.com')
        ->and($csv)->not->toContain('bob@example.com')
        ->and($csv)->toContain("'=HYPERLINK")
        ->and(AuditLog::query()->where('action', 'newsletter.exported')->where('admin_user_id', $admin->id)->sole()->meta)
        ->toMatchArray(['rows' => 1, 'filters' => ['status' => 'confirmed']]);
});

it('exports feedback for administrators who can view it, and nobody else', function () {
    actingAsAdmin(AdminRole::Operations);
    $order = Fixtures::paidOrder(OrderStatus::Delivered);
    Feedback::query()->create(['order_id' => $order->id, 'service_id' => $order->service_id, 'rating' => 5, 'liked' => 'Great']);

    $response = $this->get(route('admin.support.exports.feedback'));
    $response->assertOk();
    expect($response->streamedContent())->toContain($order->reference);

    $this->flushSession();
    actingAsAdmin(AdminRole::Finance);
    $this->get(route('admin.support.exports.feedback'))->assertForbidden();
    $this->get(route('admin.support.exports.newsletter'))->assertForbidden();
});
