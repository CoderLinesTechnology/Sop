<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promotional campaigns (server-timed, optionally with a countdown) and
     * coupons. Monetary amounts are integers in the currency's minor unit.
     */
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('label', 80)->nullable();
            $table->string('description', 500)->nullable();
            $table->decimal('percent_off', 5, 2)->nullable();
            $table->unsignedBigInteger('amount_off')->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedBigInteger('max_discount_amount')->nullable();
            $table->boolean('applies_to_all_services')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('show_countdown')->default(false);
            $table->boolean('show_banner')->default(false);
            $table->string('banner_text')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::create('promotion_service', function (Blueprint $table) {
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->primary(['promotion_id', 'service_id']);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('description')->nullable();
            $table->decimal('percent_off', 5, 2)->nullable();
            $table->unsignedBigInteger('amount_off')->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedBigInteger('max_discount_amount')->nullable();
            $table->boolean('applies_to_all_services')->default(true);
            $table->unsignedBigInteger('min_order_amount')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('max_uses_per_email')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('first_time_customers_only')->default(false);
            $table->string('customer_email')->nullable();
            $table->boolean('stackable_with_promotions')->default(false);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('coupon_service', function (Blueprint $table) {
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->primary(['coupon_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_service');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('promotion_service');
        Schema::dropIfExists('promotions');
    }
};
