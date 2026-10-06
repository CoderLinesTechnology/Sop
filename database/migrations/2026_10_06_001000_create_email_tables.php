<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-editable email templates, a log of every transactional email and
     * the delivery events reported back by the email provider.
     */
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('subject');
            $table->longText('body');
            $table->json('variables')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template_key', 60)->nullable()->index();
            $table->string('to_email')->index();
            $table->string('subject');
            $table->longText('html_body')->nullable();
            $table->longText('text_body')->nullable();
            $table->json('attachments')->nullable();
            $table->string('status', 20)->index();
            $table->string('mailer', 30)->nullable();
            $table->string('provider_message_id')->nullable()->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('email_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('event_type', 40);
            $table->string('provider_message_id')->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_events');
        Schema::dropIfExists('emails');
        Schema::dropIfExists('email_templates');
    }
};
