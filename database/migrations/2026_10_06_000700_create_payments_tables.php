<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payments, raw provider events (for idempotency and forensics) and refunds.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('revision_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 20)->default('order');
            $table->string('provider', 20)->default('paystack');
            $table->string('reference', 64)->unique();
            $table->string('access_code', 100)->nullable();
            $table->string('authorization_url', 500)->nullable();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 30)->index();
            $table->string('provider_transaction_id', 64)->nullable()->index();
            $table->string('channel', 40)->nullable();
            $table->string('gateway_response')->nullable();
            $table->string('customer_email')->nullable();
            $table->unsignedBigInteger('fees')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->json('verification_data')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('mismatch_details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('event_type', 60);
            $table->string('reference', 64)->nullable()->index();
            $table->string('provider_event_id', 100)->nullable();
            $table->char('payload_hash', 64)->unique();
            $table->boolean('signature_valid');
            $table->string('source_ip', 45)->nullable();
            $table->json('payload')->nullable();
            $table->string('processing_status', 30)->default('pending');
            $table->text('processing_notes')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->text('reason');
            $table->string('status', 20)->index();
            $table->string('requested_by', 20)->default('admin');
            $table->foreignId('requested_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('approved_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('provider_refund_id', 64)->nullable();
            $table->string('provider_status', 40)->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->timestamp('processed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
    }
};
