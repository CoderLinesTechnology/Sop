<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Referral programme: referrers, their daily link visits, the attribution
     * of an order to a referrer, and the commission ledger.
     *
     * The ledger is insert-only money history: an entry per confirmed payment
     * (order payment or revision fee) and a negative entry per processed
     * refund. Unique keys on (payment_id, kind) and (refund_id, kind) make a
     * payment confirmed twice (webhook + callback) or a refund finalised twice
     * produce exactly one entry. Every timestamp is nullable (MariaDB would
     * otherwise turn the first NOT NULL TIMESTAMP into an auto-updating one).
     */
    public function up(): void
    {
        Schema::create('referrers', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 120);
            $table->string('email', 190)->nullable()->index();
            $table->string('phone', 40)->nullable();
            $table->string('code', 40)->unique();
            $table->unsignedSmallInteger('commission_rate_bp')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->text('dashboard_token')->nullable();
            $table->char('dashboard_token_hash', 64)->nullable()->unique();
            $table->timestamp('erased_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('referral_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('referrers')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('visits')->default(0);

            $table->unique(['referrer_id', 'date']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('referrer_id')->nullable()->after('coupon_discount')->constrained('referrers')->nullOnDelete();
            $table->string('referral_code', 40)->nullable()->after('referrer_id');
            $table->string('referral_source', 10)->nullable()->after('referral_code');
            $table->timestamp('referred_at')->nullable()->after('referral_source');

            // A referrer's orders by payment state (dashboard counts and list, admin aggregates). Explicit,
            // because only InnoDB indexes a foreign key by itself; it also covers the foreign key there.
            $table->index(['referrer_id', 'payment_status']);
        });

        Schema::create('referral_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('referrers')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('kind', 20);
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained('refunds')->restrictOnDelete();
            $table->bigInteger('base_amount');
            $table->char('currency', 3);
            $table->unsignedSmallInteger('rate_bp');
            $table->bigInteger('amount');
            $table->timestamp('paid_at')->nullable();
            $table->string('payout_reference', 100)->nullable();
            $table->foreignId('paid_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            // Idempotency: one entry per confirmed payment, one reversal per processed refund.
            $table->unique(['payment_id', 'kind']);
            $table->unique(['refund_id', 'kind']);
            // Per-order sums, balance due / paid per referrer, and the referrer's ledger in date order.
            $table->index('order_id');
            $table->index(['referrer_id', 'paid_at']);
            $table->index(['referrer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_commissions');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['referrer_id']);
            $table->dropIndex(['referrer_id', 'payment_status']);
            $table->dropColumn('referrer_id');
            $table->dropColumn(['referral_code', 'referral_source', 'referred_at']);
        });

        Schema::dropIfExists('referral_visits');
        Schema::dropIfExists('referrers');
    }
};
