<?php

use App\Domain\Pricing\CouponReservations;
use App\Domain\Pricing\PriceCalculator;
use App\Enums\CouponRedemptionStatus;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\Service;

function coupon(array $attributes = []): Coupon
{
    return Coupon::query()->create(array_merge([
        'code' => 'SAVE15',
        'percent_off' => 15,
        'applies_to_all_services' => true,
        'is_active' => true,
        'stackable_with_promotions' => true,
    ], $attributes));
}

function launchPromotion(int $percent = 10): Promotion
{
    return Promotion::query()->create([
        'name' => 'Launch',
        'label' => 'Launch offer',
        'percent_off' => $percent,
        'applies_to_all_services' => true,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(3),
        'is_active' => true,
        'priority' => 1,
    ]);
}

beforeEach(function () {
    $this->service = Service::factory()->create(['price' => 8900, 'currency' => 'USD']);
    $this->prices = app(PriceCalculator::class);
});

it('stacks a stackable coupon on top of the running promotion', function () {
    launchPromotion(10);
    coupon(['code' => 'NEWYEAR15', 'percent_off' => 15]);

    $quote = $this->prices->quote($this->service, 'newyear15');

    expect($quote->promotionDiscount)->toBe(890)
        ->and($quote->couponDiscount)->toBe(1202)
        ->and($quote->total)->toBe(6808)
        ->and($quote->couponStatus)->toBe('applied');
});

it('keeps the better price when a coupon cannot be combined with the promotion', function () {
    launchPromotion(10);
    coupon(['code' => 'WELCOME5', 'percent_off' => 5, 'stackable_with_promotions' => false]);
    coupon(['code' => 'BIG20', 'percent_off' => 20, 'stackable_with_promotions' => false]);

    $smaller = $this->prices->quote($this->service, 'WELCOME5');
    expect($smaller->couponStatus)->toBe('not_combinable')->and($smaller->total)->toBe(8010);

    $bigger = $this->prices->quote($this->service, 'BIG20');
    expect($bigger->couponStatus)->toBe('applied')
        ->and($bigger->promotionDiscount)->toBe(0)
        ->and($bigger->total)->toBe(7120);
});

it('rejects expired, not-yet-active, inactive and unknown coupons', function (array $attributes, string $message) {
    coupon($attributes);

    $quote = $this->prices->quote($this->service, 'SAVE15');

    expect($quote->couponStatus)->toBe('invalid')
        ->and($quote->couponMessage)->toBe($message)
        ->and($quote->total)->toBe(8900);
})->with([
    'expired' => [['expires_at' => now()->subMinute()], 'This coupon has expired.'],
    'not started' => [['starts_at' => now()->addDay()], "This coupon isn't active yet."],
    'inactive' => [['is_active' => false], "This coupon code isn't valid."],
    'unknown' => [['code' => 'OTHER'], "This coupon code isn't valid."],
]);

it('only applies a service-restricted coupon to its services', function () {
    $other = Service::factory()->create();
    coupon(['applies_to_all_services' => false])->services()->attach($other);

    expect($this->prices->quote($this->service, 'SAVE15')->couponStatus)->toBe('invalid')
        ->and($this->prices->quote($other, 'SAVE15')->couponStatus)->toBe('applied');
});

it('enforces a minimum order amount and caps fixed discounts at the price', function () {
    coupon(['code' => 'MIN', 'percent_off' => 10, 'min_order_amount' => 10000]);
    coupon(['code' => 'HUGE', 'percent_off' => null, 'amount_off' => 50000, 'currency' => 'USD']);

    expect($this->prices->quote($this->service, 'MIN')->couponStatus)->toBe('invalid')
        ->and($this->prices->quote($this->service, 'HUGE')->total)->toBe(0);
});

it('counts reservations towards the usage limit so the last use cannot be sold twice', function () {
    $limited = coupon(['max_uses' => 1]);
    $first = Order::factory()->create(['service_id' => $this->service->id, 'coupon_id' => $limited->id, 'coupon_code' => 'SAVE15']);
    $second = Order::factory()->create(['service_id' => $this->service->id]);
    $reservations = app(CouponReservations::class);

    expect($reservations->reserve($first, 1335, 60))->toBeTrue()
        ->and($this->prices->quote($this->service, 'SAVE15', null, $second)->couponStatus)->toBe('invalid');

    $reservations->release($first);

    expect($this->prices->quote($this->service, 'SAVE15', null, $second)->couponStatus)->toBe('applied');
});

it('limits uses per email address', function () {
    $once = coupon(['max_uses_per_email' => 1]);
    $past = Order::factory()->paid()->create(['service_id' => $this->service->id, 'email' => 'ama@example.com']);
    CouponRedemption::query()->create([
        'coupon_id' => $once->id, 'order_id' => $past->id, 'email' => 'ama@example.com',
        'discount_amount' => 1335, 'currency' => 'USD', 'status' => CouponRedemptionStatus::Redeemed, 'redeemed_at' => now(),
    ]);

    expect($this->prices->quote($this->service, 'SAVE15', 'ama@example.com')->couponStatus)->toBe('invalid')
        ->and($this->prices->quote($this->service, 'SAVE15', 'kofi@example.com')->couponStatus)->toBe('applied');
});
