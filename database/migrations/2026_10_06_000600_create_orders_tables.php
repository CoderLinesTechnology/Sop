<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Orders and everything the customer submits with them.
     *
     * An order is created as a draft (FORM_SUBMITTED) when the customer
     * continues to review, bound to their browser through a hashed checkout
     * token. Customers reach a paid order through signed, expiring links that
     * embed the order's public_id and access_version (bumping the version
     * revokes every previously issued link). Internal ids are never exposed.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('reference', 20)->unique();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->index();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 40)->nullable();
            $table->string('applicant_name')->nullable();
            $table->string('status', 40);
            $table->string('payment_status', 30);
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_amount');
            $table->foreignId('promotion_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('promotion_discount')->default(0);
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('coupon_code', 40)->nullable();
            $table->unsignedBigInteger('coupon_discount')->default(0);
            $table->unsignedBigInteger('total_amount');
            $table->json('pricing_snapshot');
            $table->json('service_snapshot');
            $table->string('institution')->nullable();
            $table->string('programme')->nullable();
            $table->string('degree_level', 60)->nullable();
            $table->char('country_code', 2)->nullable()->index();
            $table->string('intake', 60)->nullable();
            $table->date('deadline')->nullable();
            $table->text('essay_prompt')->nullable();
            $table->unsignedInteger('word_limit')->nullable();
            $table->string('language_variant', 10)->nullable();
            $table->char('checkout_token_hash', 64)->nullable()->index();
            $table->unsignedSmallInteger('access_version')->default(1);
            $table->string('payment_reference', 64)->nullable()->unique();
            $table->timestamp('fulfillment_started_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('estimated_ready_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->string('pause_reason')->nullable();
            $table->timestamp('delay_notified_at')->nullable();
            $table->timestamp('needs_info_at')->nullable();
            $table->unsignedTinyInteger('revisions_allowed')->default(0);
            $table->unsignedTinyInteger('revisions_used')->default(0);
            $table->timestamp('revision_deadline_at')->nullable();
            $table->unsignedTinyInteger('risk_score')->default(0);
            $table->json('risk_flags')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('utm')->nullable();
            $table->boolean('create_account')->default(false);
            $table->timestamp('retention_until')->nullable()->index();
            $table->timestamp('data_purged_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['payment_status', 'created_at']);
            $table->index(['service_id', 'created_at']);
        });

        Schema::create('order_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('field_key', 60);
            $table->string('label');
            $table->string('type', 30);
            $table->string('section', 30);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'field_key']);
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->string('actor_type', 20);
            $table->foreignId('admin_user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'created_at']);
        });

        Schema::create('order_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->unsignedBigInteger('discount_amount');
            $table->char('currency', 3);
            $table->string('status', 20);
            $table->timestamp('reserved_until')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['coupon_id', 'status']);
            $table->index(['coupon_id', 'email', 'status']);
        });

        Schema::create('uploaded_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->char('draft_token_hash', 64)->nullable()->index();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('field_key', 60)->nullable();
            $table->string('purpose', 40)->nullable();
            $table->string('original_name');
            $table->string('extension', 10);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64)->index();
            $table->string('disk', 30);
            $table->string('path');
            $table->boolean('is_encrypted')->default(true);
            $table->string('encryption_key_id', 20)->nullable();
            $table->string('scan_status', 20)->default('pending');
            $table->string('scan_engine', 40)->nullable();
            $table->string('scan_result')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->string('extraction_status', 20)->default('pending');
            $table->longText('extracted_text')->nullable();
            $table->unsignedInteger('extracted_chars')->nullable();
            $table->unsignedSmallInteger('page_count')->nullable();
            $table->string('uploaded_ip', 45)->nullable();
            $table->timestamp('attached_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('applicants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('full_name')->nullable();
            $table->json('profile');
            $table->json('missing_information')->nullable();
            $table->json('source_files')->nullable();
            $table->string('model', 80)->nullable();
            $table->timestamps();
        });

        Schema::create('information_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('source', 10)->default('ai');
            $table->json('questions');
            $table->json('answers')->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('requested_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });

        Schema::create('revisions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('number');
            $table->text('request_text');
            $table->string('status', 30);
            $table->string('mode', 10)->default('ai');
            $table->unsignedBigInteger('fee_amount')->default(0);
            $table->char('currency', 3);
            $table->timestamp('requested_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('rejected_reason')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revisions');
        Schema::dropIfExists('information_requests');
        Schema::dropIfExists('applicants');
        Schema::dropIfExists('uploaded_files');
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('order_notes');
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_answers');
        Schema::dropIfExists('orders');
    }
};
