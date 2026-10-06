<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customer feedback, support messages, magic-link tokens, the audit log,
     * security events and privacy-friendly analytics events.
     */
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_workflow_id')->nullable()->constrained()->nullOnDelete();
            $table->json('prompt_versions')->nullable();
            $table->unsignedTinyInteger('rating');
            $table->text('liked')->nullable();
            $table->text('improve')->nullable();
            $table->timestamps();
        });

        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email');
            $table->string('order_reference', 20)->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject')->nullable();
            $table->text('message');
            $table->string('status', 20)->default('new')->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->foreignId('handled_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_login_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_type', 20);
            $table->string('actor_label')->nullable();
            $table->string('action', 80)->index();
            $table->string('target_type', 80)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->string('target_label')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['target_type', 'target_id']);
        });

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 60)->index();
            $table->string('severity', 10)->default('medium');
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('email')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path', 500)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->string('event', 40);
            $table->string('path', 500)->nullable();
            $table->unsignedBigInteger('service_id')->nullable()->index();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->char('visitor_hash', 16)->nullable();
            $table->string('referrer_host')->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            $table->string('device', 10)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->bigInteger('value')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['event', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('customer_login_tokens');
        Schema::dropIfExists('contact_messages');
        Schema::dropIfExists('feedback');
    }
};
