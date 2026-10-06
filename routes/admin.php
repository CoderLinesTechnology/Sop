<?php

/*
|--------------------------------------------------------------------------
| Admin support routes
|--------------------------------------------------------------------------
|
| Routes used by the Filament admin panel outside of Filament pages (for
| example streaming a document preview). Every route here must require the
| "admin" guard and an explicit permission check.
|
| Each controller action authorizes a policy ability (OrderPolicy,
| NewsletterSubscriberPolicy, FeedbackPolicy) and audits access to customer
| content. EnsureAdminSession mirrors the panel's own gate (active admin with
| a role and MFA configured) and sends guests to the admin sign-in page.
|
*/

use App\Filament\Support\Operations\Http\EnsureAdminSession;
use App\Filament\Support\Operations\Http\SupportController;
use App\Http\Middleware\AdminSessionTimeout;
use App\Http\Middleware\RestrictAdminIps;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', RestrictAdminIps::class, EnsureAdminSession::class, 'auth:admin', AdminSessionTimeout::class, 'throttle:120,1'])
    ->prefix(trim((string) config('statementra.security.admin_path', 'admin'), '/').'/support')
    ->name('admin.support.')
    ->group(function () {
        Route::get('orders/{order:public_id}/files/{file:uuid}', [SupportController::class, 'file'])
            ->scopeBindings()
            ->name('orders.files.download');

        Route::get('orders/{order:public_id}/documents/{documentVersion:uuid}/{format}', [SupportController::class, 'document'])
            ->whereIn('format', ['pdf', 'docx'])
            ->scopeBindings()
            ->name('orders.documents.show');

        Route::get('emails/{email:uuid}/preview', [SupportController::class, 'emailPreview'])
            ->name('emails.preview');

        Route::get('exports/newsletter-subscribers', [SupportController::class, 'newsletterExport'])
            ->name('exports.newsletter');

        Route::get('exports/feedback', [SupportController::class, 'feedbackExport'])
            ->name('exports.feedback');
    });
