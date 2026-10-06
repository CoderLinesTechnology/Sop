<?php

use App\Enums\AdminRole;
use App\Filament\Resources\Coupons\CouponResource;
use App\Filament\Resources\Coupons\Pages\CreateCoupon;
use App\Filament\Resources\Coupons\Pages\EditCoupon;
use App\Filament\Resources\Coupons\Pages\ListCoupons;
use App\Filament\Resources\Promotions\Pages\CreatePromotion;
use App\Filament\Resources\Promotions\Pages\EditPromotion;
use App\Filament\Resources\Promotions\Pages\ListPromotions;
use App\Filament\Resources\Promotions\PromotionResource;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\Service;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

it('limits coupons and promotions to administrators who manage pricing', function () {
    actingAsAdmin(AdminRole::Operations);

    $this->get(CouponResource::getUrl('index'))->assertForbidden();
    $this->get(PromotionResource::getUrl('index'))->assertForbidden();
});

it('creates a percentage coupon with an upper-case code and audits it', function () {
    $admin = actingAsAdmin(AdminRole::Finance);

    Livewire::test(CreateCoupon::class)
        ->fillForm([
            'code' => ' welcome-10 ',
            'description' => 'Welcome offer',
            'discount_type' => 'percent',
            'percent_off' => 10,
            'max_discount_amount' => 20,
            'min_order_amount' => 50.5,
            'max_uses' => 100,
            'max_uses_per_email' => 1,
            'first_time_customers_only' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $coupon = Coupon::query()->sole();

    expect($coupon->code)->toBe('WELCOME-10')
        ->and((float) $coupon->percent_off)->toBe(10.0)
        ->and($coupon->amount_off)->toBeNull()
        ->and($coupon->max_discount_amount)->toBe(2000)
        ->and($coupon->min_order_amount)->toBe(5050)
        ->and($coupon->created_by_admin_id)->toBe($admin->id);

    $audit = AuditLog::query()->where('action', 'coupon.created')->sole();
    expect($audit->after['code'])->toBe('WELCOME-10');
});

it('stores a fixed-amount coupon in minor units with a currency', function () {
    actingAsAdmin(AdminRole::Finance);
    $service = Service::factory()->create();

    Livewire::test(CreateCoupon::class)
        ->fillForm([
            'code' => 'FLAT15',
            'discount_type' => 'amount',
            'amount_off' => 15.5,
            'currency' => 'USD',
            'applies_to_all_services' => false,
            'services' => [$service->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $coupon = Coupon::query()->sole();

    expect($coupon->amount_off)->toBe(1550)
        ->and($coupon->percent_off)->toBeNull()
        ->and($coupon->currency)->toBe('USD')
        ->and($coupon->applies_to_all_services)->toBeFalse()
        ->and($coupon->services()->pluck('services.id')->all())->toBe([$service->id]);
});

it('requires exactly one of a percentage or a fixed amount', function () {
    actingAsAdmin(AdminRole::Finance);

    Livewire::test(CreateCoupon::class)
        ->fillForm(['code' => 'NONE', 'discount_type' => 'percent', 'percent_off' => null])
        ->call('create')
        ->assertHasFormErrors(['percent_off' => 'required']);

    Livewire::test(CreateCoupon::class)
        ->fillForm(['code' => 'BOTH', 'discount_type' => 'percent', 'percent_off' => 10, 'amount_off' => 5])
        ->call('create')
        ->assertHasFormErrors(['percent_off']);

    Livewire::test(CreateCoupon::class)
        ->fillForm(['code' => 'TOOMUCH', 'discount_type' => 'percent', 'percent_off' => 150])
        ->call('create')
        ->assertHasFormErrors(['percent_off']);

    Livewire::test(CreateCoupon::class)
        ->fillForm(['code' => 'NOCURRENCY', 'discount_type' => 'amount', 'amount_off' => 5, 'currency' => null])
        ->call('create')
        ->assertHasFormErrors(['currency' => 'required']);

    expect(Coupon::query()->count())->toBe(0);
});

it('rejects a duplicate code, including archived coupons', function () {
    actingAsAdmin(AdminRole::Finance);
    Coupon::query()->create(['code' => 'SAVE10', 'percent_off' => 10, 'is_active' => true])->delete();

    Livewire::test(CreateCoupon::class)
        ->fillForm(['code' => 'save10', 'discount_type' => 'percent', 'percent_off' => 5])
        ->call('create')
        ->assertHasFormErrors(['code']);
});

it('switches a coupon to a fixed amount and audits the before and after values', function () {
    actingAsAdmin(AdminRole::Finance);
    $coupon = Coupon::query()->create(['code' => 'SWITCH', 'percent_off' => 10, 'max_discount_amount' => 1000, 'is_active' => true]);

    Livewire::test(EditCoupon::class, ['record' => $coupon->getRouteKey()])
        ->assertSchemaStateSet(['discount_type' => 'percent', 'percent_off' => '10.00', 'max_discount_amount' => 10])
        ->fillForm(['discount_type' => 'amount', 'amount_off' => 12, 'currency' => 'USD'])
        ->call('save')
        ->assertHasNoFormErrors();

    $coupon->refresh();
    expect($coupon->percent_off)->toBeNull()
        ->and($coupon->amount_off)->toBe(1200)
        ->and($coupon->max_discount_amount)->toBeNull();

    $audit = AuditLog::query()->where('action', 'coupon.updated')->sole();
    expect($audit->before)->toMatchArray(['percent_off' => '10.00', 'amount_off' => null])
        ->and($audit->after)->toMatchArray(['percent_off' => null, 'amount_off' => 1200]);
});

it('archives and restores coupons with an audit trail', function () {
    actingAsAdmin(AdminRole::Finance);
    $coupon = Coupon::query()->create(['code' => 'ARCHIVE', 'percent_off' => 10, 'is_active' => true]);

    Livewire::test(ListCoupons::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($coupon));

    expect($coupon->fresh()->trashed())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'coupon.deleted')->count())->toBe(1);

    Livewire::test(ListCoupons::class)
        ->filterTable('trashed', true)
        ->callAction(TestAction::make('restore')->table($coupon));

    expect($coupon->fresh()->trashed())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'coupon.restored')->count())->toBe(1);
});

it('shows coupon usage from redemptions', function () {
    actingAsAdmin(AdminRole::Finance);
    $coupon = Coupon::query()->create(['code' => 'USED', 'percent_off' => 10, 'max_uses' => 5, 'is_active' => true]);
    $order = Order::factory()->create();
    $coupon->redemptions()->create(['order_id' => $order->id, 'email' => $order->email, 'discount_amount' => 890, 'currency' => 'USD', 'status' => 'redeemed', 'redeemed_at' => now()]);

    Livewire::test(ListCoupons::class)
        ->assertCanSeeTableRecords([$coupon])
        ->assertSee('1 redeemed of 5');
});

it('creates a promotion with a schedule and shows whether it is running', function () {
    actingAsAdmin(AdminRole::Finance);

    Livewire::test(CreatePromotion::class)
        ->fillForm([
            'name' => 'Back to school',
            'label' => 'LIMITED-TIME OFFER',
            'priority' => 5,
            'discount_type' => 'percent',
            'percent_off' => 15,
            'starts_at' => now()->subHour()->format('Y-m-d H:i'),
            'ends_at' => now()->addDay()->format('Y-m-d H:i'),
            'show_countdown' => true,
            'show_banner' => true,
            'banner_text' => '15% off everything this week',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $promotion = Promotion::query()->sole();

    expect($promotion->isRunning())->toBeTrue()
        ->and((float) $promotion->percent_off)->toBe(15.0)
        ->and(AuditLog::query()->where('action', 'promotion.created')->count())->toBe(1);

    Livewire::test(ListPromotions::class)
        ->assertCanSeeTableRecords([$promotion])
        ->assertSee('Running now');
});

it('validates promotion banners and end times', function () {
    actingAsAdmin(AdminRole::Finance);

    Livewire::test(CreatePromotion::class)
        ->fillForm([
            'name' => 'Broken',
            'priority' => 0,
            'discount_type' => 'percent',
            'percent_off' => 10,
            'starts_at' => now()->format('Y-m-d H:i'),
            'ends_at' => now()->subDay()->format('Y-m-d H:i'),
            'show_banner' => true,
            'banner_text' => null,
            'show_countdown' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['ends_at', 'banner_text' => 'required']);
});

it('audits promotion changes', function () {
    actingAsAdmin(AdminRole::Finance);
    $promotion = Promotion::query()->create(['name' => 'Spring', 'percent_off' => 10, 'is_active' => true, 'priority' => 0]);

    Livewire::test(EditPromotion::class, ['record' => $promotion->getRouteKey()])
        ->fillForm(['percent_off' => 20])
        ->call('save')
        ->assertHasNoFormErrors();

    $audit = AuditLog::query()->where('action', 'promotion.updated')->sole();
    expect($audit->before)->toMatchArray(['percent_off' => '10.00'])
        ->and($audit->after)->toMatchArray(['percent_off' => '20.00']);
});
