<?php

use App\Http\Controllers\Checkout\ApplicationController;
use App\Http\Controllers\Checkout\CheckoutController;
use App\Http\Controllers\Checkout\OrderController;
use App\Http\Controllers\Checkout\UploadController;
use App\Http\Controllers\Site\AccountController;
use App\Http\Controllers\Site\ArticleController;
use App\Http\Controllers\Site\BeaconController;
use App\Http\Controllers\Site\ContactController;
use App\Http\Controllers\Site\DevPaystackController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\NewsletterController;
use App\Http\Controllers\Site\OrderLookupController;
use App\Http\Controllers\Site\PageController;
use App\Http\Controllers\Site\PartnerApplicationController;
use App\Http\Controllers\Site\PartnerController;
use App\Http\Controllers\Site\ReferralConsentController;
use App\Http\Controllers\Site\SeoController;
use App\Http\Controllers\Site\ServiceController;
use App\Http\Controllers\Webhooks\EmailWebhookController;
use App\Http\Controllers\Webhooks\PaystackWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public website
|--------------------------------------------------------------------------
*/

Route::middleware('track')->group(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
    Route::get('/services/{slug}', [ServiceController::class, 'show'])->name('services.show');
    Route::get('/how-it-works', [PageController::class, 'howItWorks'])->name('pages.how-it-works');
    Route::get('/faq', [PageController::class, 'faq'])->name('pages.faq');
    Route::get('/resources', [ArticleController::class, 'index'])->name('resources.index');
    Route::get('/resources/feed.xml', [SeoController::class, 'feed'])->name('resources.feed');
    Route::get('/resources/category/{slug}', [ArticleController::class, 'category'])->name('resources.category');
    Route::get('/resources/{slug}', [ArticleController::class, 'show'])->name('resources.show');
    Route::get('/contact', [ContactController::class, 'show'])->name('contact.show');
    Route::get('/{page}', [PageController::class, 'show'])->whereIn('page', PageController::STANDARD_PAGES)->name('pages.show');
});

Route::middleware('throttle:forms')->group(function () {
    Route::post('/contact', [ContactController::class, 'store'])->name('contact.store');
    Route::post('/newsletter', [NewsletterController::class, 'subscribe'])->name('newsletter.subscribe');
});
Route::get('/newsletter/confirm/{token}', [NewsletterController::class, 'confirm'])->where('token', '[A-Za-z0-9]{48}')->name('newsletter.confirm');
Route::get('/newsletter/unsubscribe/{token}', [NewsletterController::class, 'unsubscribe'])->where('token', '[A-Za-z0-9]{48}')->name('newsletter.unsubscribe');

/*
|--------------------------------------------------------------------------
| Ordering: application → review → payment → done
|--------------------------------------------------------------------------
*/

Route::get('/start/{slug?}', [ApplicationController::class, 'create'])->middleware('track')->name('order.start');
Route::post('/start/{slug}', [ApplicationController::class, 'store'])->middleware('throttle:order-submit')->name('order.store');
Route::post('/start/{slug}/uploads', [UploadController::class, 'store'])->middleware('throttle:uploads')->name('order.uploads.store');
Route::delete('/uploads/{uuid}', [UploadController::class, 'destroy'])->middleware('throttle:uploads')->whereUuid('uuid')->name('order.uploads.destroy');

Route::get('/checkout/callback', [CheckoutController::class, 'callback'])->middleware('throttle:checkout-callback')->name('checkout.callback');
Route::prefix('/checkout/{reference}')->where(['reference' => 'ST-[0-9A-Za-z]{4}-[0-9A-Za-z]{4}'])->group(function () {
    Route::get('/review', [CheckoutController::class, 'review'])->name('checkout.review');
    Route::get('/payment', [CheckoutController::class, 'payment'])->name('checkout.payment');
    Route::post('/quote', [CheckoutController::class, 'quote'])->middleware('throttle:quote')->name('checkout.quote');
    Route::post('/pay', [CheckoutController::class, 'pay'])->middleware('throttle:checkout')->name('checkout.pay');
});

/*
|--------------------------------------------------------------------------
| Customer order page (signed link, session grant or verified account)
|--------------------------------------------------------------------------
*/

Route::middleware(['throttle:order-access', 'order.access'])->prefix('/o/{order}')->where(['order' => '[0-9A-HJKMNP-TV-Z]{26}'])->group(function () {
    Route::get('/', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/progress', [OrderController::class, 'progress'])->name('orders.progress');
    Route::get('/documents/{version}/{format}', [OrderController::class, 'download'])->whereUuid('version')->whereIn('format', ['pdf', 'docx'])->name('orders.download');
    // Reloading the page after answering re-requests the form's address: show the order instead of an error.
    Route::get('/information', [OrderController::class, 'informationPage'])->name('orders.information.page');
    Route::middleware('throttle:order-actions')->group(function () {
        Route::post('/information', [OrderController::class, 'answerInformation'])->name('orders.information');
        Route::post('/revisions', [OrderController::class, 'requestRevision'])->name('orders.revisions.store');
        Route::post('/feedback', [OrderController::class, 'feedback'])->name('orders.feedback');
    });
});

/*
|--------------------------------------------------------------------------
| Referral partners: private dashboard link (no password) and "email it again"
|--------------------------------------------------------------------------
*/

Route::get('/partner', [PartnerController::class, 'request'])->name('partner.request');
Route::post('/partner', [PartnerController::class, 'send'])->middleware('throttle:partner-link')->name('partner.send');
Route::get('/partner/{token}', [PartnerController::class, 'show'])->where('token', '[A-Za-z0-9]{20,100}')->middleware('throttle:partner-dashboard')->name('partner.dashboard');
Route::middleware('throttle:partner-actions')->where(['token' => '[A-Za-z0-9]{20,100}'])->group(function () {
    Route::post('/partner/{token}/payouts', [PartnerController::class, 'requestPayout'])->name('partner.payouts.request');
    Route::post('/partner/{token}/payouts/{payout}/cancel', [PartnerController::class, 'cancelPayout'])->whereUlid('payout')->name('partner.payouts.cancel');
    Route::post('/partner/{token}/payment-details', [PartnerController::class, 'changePaymentDetails'])->name('partner.details.change');
});
// Confirming payment details from the email: GET shows the change, only the button (POST) applies it.
Route::get('/partner/details/{token}', [PartnerController::class, 'confirmDetailsPage'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:partner-dashboard')->name('partner.details.confirm');
Route::post('/partner/details/{token}', [PartnerController::class, 'confirmDetails'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:partner-actions')->name('partner.details.apply');

// Referral consent prompt (remember the referral link for 30 days, or this visit only).
Route::post('/referral/consent', ReferralConsentController::class)->middleware('throttle:referral-consent')->name('referral.consent');

// Become a partner: application, email confirmation (GET shows, POST confirms).
Route::get('/become-a-partner', [PartnerApplicationController::class, 'show'])->middleware('track')->name('partners.apply');
Route::post('/become-a-partner', [PartnerApplicationController::class, 'store'])->middleware('throttle:partner-apply')->name('partners.apply.store');
Route::get('/become-a-partner/confirm/{token}', [PartnerApplicationController::class, 'confirmPage'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:partner-dashboard')->name('partners.apply.confirm');
Route::post('/become-a-partner/confirm/{token}', [PartnerApplicationController::class, 'confirm'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:partner-actions')->name('partners.apply.confirm.store');

Route::get('/find-my-order', [OrderLookupController::class, 'show'])->name('orders.lookup');
Route::post('/find-my-order', [OrderLookupController::class, 'send'])->middleware('throttle:magic-link')->name('orders.lookup.send');

/*
|--------------------------------------------------------------------------
| Optional customer accounts (passwordless)
|--------------------------------------------------------------------------
*/

Route::get('/account/login', [AccountController::class, 'login'])->name('account.login');
Route::post('/account/login', [AccountController::class, 'sendLink'])->middleware('throttle:magic-link')->name('account.send-link');
Route::get('/account/verify/{token}', [AccountController::class, 'verify'])->where('token', '[A-Za-z0-9]{64}')->name('account.verify');
Route::middleware('auth')->group(function () {
    Route::get('/account', [AccountController::class, 'index'])->name('account.index');
    Route::post('/account/logout', [AccountController::class, 'logout'])->name('account.logout');
});

/*
|--------------------------------------------------------------------------
| Webhooks, analytics beacon, SEO
|--------------------------------------------------------------------------
*/

Route::post('/webhooks/paystack', PaystackWebhookController::class)->middleware('throttle:webhooks')->name('webhooks.paystack');
Route::post('/webhooks/email/{provider}', EmailWebhookController::class)->whereIn('provider', ['resend', 'postmark'])->middleware('throttle:webhooks')->name('webhooks.email');
Route::post('/beacon', BeaconController::class)->middleware('throttle:beacon')->name('beacon');
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('robots');
Route::get('/llms.txt', [SeoController::class, 'llms'])->name('llms');
Route::get('/site.webmanifest', [SeoController::class, 'manifest'])->name('manifest');
Route::get('/{key}.txt', [SeoController::class, 'indexNowKey'])->where('key', '[a-f0-9]{32}')->name('indexnow.key');

/*
|--------------------------------------------------------------------------
| Development only: simulated Paystack checkout (PAYSTACK_MODE=mock)
|--------------------------------------------------------------------------
*/

if (! app()->isProduction()) {
    Route::get('/dev/paystack/{reference}', [DevPaystackController::class, 'show'])->middleware('signed')->name('dev.paystack.checkout');
    Route::post('/dev/paystack/{reference}', [DevPaystackController::class, 'complete'])->name('dev.paystack.complete');
}

require __DIR__.'/admin.php';
