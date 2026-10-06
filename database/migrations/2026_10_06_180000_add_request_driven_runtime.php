<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns for the request-driven runtime (no queue worker, no cron): work is
 * claimed with short leases and retried at next_* timestamps by whichever web
 * request, webhook or heartbeat comes along next.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_jobs', function (Blueprint $table) {
            $table->timestamp('next_run_at')->nullable()->after('current_stage');
            $table->timestamp('leased_until')->nullable()->after('next_run_at');
            $table->string('lease_token', 40)->nullable()->after('leased_until');
            $table->index(['status', 'next_run_at']);
        });

        Schema::table('emails', function (Blueprint $table) {
            $table->timestamp('next_attempt_at')->nullable()->after('attempts');
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::table('payment_events', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempts')->default(0)->after('processing_status');
            $table->index(['processing_status', 'created_at']);
        });

        Schema::table('uploaded_files', function (Blueprint $table) {
            $table->unsignedTinyInteger('extraction_attempts')->default(0)->after('extraction_status');
        });

        // One row per heartbeat task: when it last ran and how it went.
        Schema::create('system_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->unsignedInteger('interval_seconds');
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->string('last_status', 20)->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('last_duration_ms')->nullable();
            $table->unsignedBigInteger('run_count')->default(0);
            $table->unsignedBigInteger('failure_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_tasks');

        Schema::table('uploaded_files', fn (Blueprint $table) => $table->dropColumn('extraction_attempts'));

        Schema::table('payment_events', function (Blueprint $table) {
            $table->dropIndex(['processing_status', 'created_at']);
            $table->dropColumn('attempts');
        });

        Schema::table('emails', function (Blueprint $table) {
            $table->dropIndex(['status', 'next_attempt_at']);
            $table->dropColumn('next_attempt_at');
        });

        Schema::table('ai_jobs', function (Blueprint $table) {
            $table->dropIndex(['status', 'next_run_at']);
            $table->dropColumn(['next_run_at', 'leased_until', 'lease_token']);
        });
    }
};
