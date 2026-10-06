<?php

use App\Domain\Orders\CheckoutSession;
use App\Domain\Orders\OrderAccess;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\SecurityEvent;
use App\Models\Service;
use App\Models\UploadedFile as StoredUpload;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** A draft order bound to a browser holding $token in its checkout cookie. */
function draftOrder(string $token, array $attributes = []): Order
{
    $service = Service::factory()->withStandardFields()->create(['price' => 8900, 'currency' => 'USD']);

    return Order::factory()->create(array_merge([
        'service_id' => $service->id,
        'status' => OrderStatus::FormSubmitted->value,
        'checkout_token_hash' => hash('sha256', $token),
        'total_amount' => 8900,
    ], $attributes));
}

function browserToken(): string
{
    return Str::random(43);
}

beforeEach(fn () => config()->set('statementra.runtime.tasks', []));

it('does not show or accept payment for a draft from another browser', function () {
    $owner = browserToken();
    $order = draftOrder($owner, ['email' => 'ama@example.com']);

    $this->withCookie(CheckoutSession::COOKIE, $owner)->get("/checkout/{$order->reference}/review")
        ->assertOk()->assertSee('Review your application');

    $this->withCookie(CheckoutSession::COOKIE, browserToken())->get("/checkout/{$order->reference}/review")
        ->assertRedirect()->assertDontSee('Review your application');

    Http::fake();
    $this->withCookie(CheckoutSession::COOKIE, browserToken())
        ->post("/checkout/{$order->reference}/pay", ['email' => 'thief@example.com', 'confirm_accuracy' => '1'])
        ->assertRedirect();
    Http::assertNothingSent();
    expect($order->refresh()->email)->toBe('ama@example.com');
});

it('charges the server-computed price whatever the browser posts', function () {
    $token = browserToken();
    $order = draftOrder($token);
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response([
        'status' => true, 'message' => 'Authorization URL created',
        'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'access_code' => 'abc', 'reference' => 'x'],
    ])]);

    $this->withCookie(CheckoutSession::COOKIE, $token)->post("/checkout/{$order->reference}/pay", [
        'email' => 'ama@example.com', 'confirm_accuracy' => '1',
        'amount' => 100, 'total_amount' => 100, 'price' => 1, 'currency' => 'NGN',
    ])->assertRedirect('https://checkout.paystack.com/abc');

    Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/transaction/initialize')
        && $request['amount'] === 8900 && $request['currency'] === 'USD');
    expect($order->refresh()->total_amount)->toBe(8900);
});

it('rate-limits coupon guessing and flags it', function () {
    $token = browserToken();
    $order = draftOrder($token);

    foreach (range(1, 15) as $i) {
        $this->withCredentials()->withCookie(CheckoutSession::COOKIE, $token)
            ->postJson("/checkout/{$order->reference}/quote", ['coupon' => 'GUESS'.$i])->assertOk();
    }

    $this->withCredentials()->withCookie(CheckoutSession::COOKIE, $token)
        ->postJson("/checkout/{$order->reference}/quote", ['coupon' => 'GUESS16'])->assertStatus(429);
    expect(SecurityEvent::query()->where('type', 'coupon_bruteforce')->exists())->toBeTrue();
});

it('does not reveal orders to guessed links and flags enumeration', function () {
    $order = Order::factory()->paid()->create();

    $this->get('/o/'.$order->public_id)->assertForbidden()->assertDontSee($order->email);
    $this->get(OrderAccess::statusUrl($order).'0')->assertForbidden();

    foreach (range(1, 20) as $i) {
        $this->get('/o/'.strtoupper((string) Str::ulid()));
    }
    expect(SecurityEvent::query()->where('type', 'order_link_enumeration')->exists())->toBeTrue();
});

it('opens an order from its signed link until the link is revoked', function () {
    $order = Order::factory()->paid()->create();
    $link = OrderAccess::statusUrl($order);

    // The signed link grants access, then redirects to the clean URL so the
    // signature does not linger in the address bar, history or referrers.
    $this->get($link)->assertRedirect(route('orders.show', $order->public_id));
    $this->get(route('orders.show', $order->public_id))->assertOk()->assertSee($order->reference);

    OrderAccess::rotate($order);
    $this->flushSession();

    $this->get($link)->assertForbidden();
});

it('rejects uploads whose contents do not match their extension', function () {
    $token = browserToken();
    $order = draftOrder($token);
    $slug = $order->service->slug;

    $disguised = UploadedFile::fake()->createWithContent('cv.pdf', "<?php system(\$_GET['c']); ?>");
    $this->withCookie(CheckoutSession::COOKIE, $token)
        ->post("/start/{$slug}/uploads", ['file' => $disguised, 'slot' => 'cv'], ['Accept' => 'application/json'])
        ->assertStatus(422);

    $executable = UploadedFile::fake()->createWithContent('cv.exe', 'MZ'.str_repeat("\0", 64));
    $this->withCookie(CheckoutSession::COOKIE, $token)
        ->post("/start/{$slug}/uploads", ['file' => $executable, 'slot' => 'cv'], ['Accept' => 'application/json'])
        ->assertStatus(422);

    expect(StoredUpload::query()->count())->toBe(0);
});

it('does not let another browser delete an upload', function () {
    $owner = browserToken();
    $order = draftOrder($owner);
    $slug = $order->service->slug;

    $response = $this->withCookie(CheckoutSession::COOKIE, $owner)->post("/start/{$slug}/uploads", [
        'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Volunteer tutor, Kumasi, 2021-2023.'),
        'slot' => 'other',
    ], ['Accept' => 'application/json'])->assertCreated();
    $uuid = $response->json('uuid');

    $this->withCredentials()->withCookie(CheckoutSession::COOKIE, browserToken())->deleteJson('/uploads/'.$uuid)->assertNotFound();
    expect(StoredUpload::query()->where('uuid', $uuid)->exists())->toBeTrue();

    $this->withCredentials()->withCookie(CheckoutSession::COOKIE, $owner)->deleteJson('/uploads/'.$uuid)->assertSuccessful();
    expect(StoredUpload::query()->where('uuid', $uuid)->exists())->toBeFalse();
});
