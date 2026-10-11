<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Become a partner" applications. An application counts only after the
     * applicant confirms their email (single-use token, hashed, 48 hours);
     * unconfirmed ones are purged after 7 days and rejected ones after the
     * configured retention period. No payment details are asked for (an
     * approved partner adds them from the dashboard) and no IP address or user
     * agent is stored.
     */
    public function up(): void
    {
        Schema::create('partner_applications', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('status', 20);
            $table->string('full_name', 120);
            $table->string('email', 190)->index();
            $table->string('phone', 40);
            $table->string('city', 80);
            $table->char('country_code', 2);
            $table->json('promotion_channels');
            $table->string('promotion_details', 500)->nullable();
            $table->string('audience_size', 20);
            $table->json('profile_links')->nullable();
            $table->text('motivation');
            $table->string('preferred_code', 40)->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->char('confirm_token_hash', 64)->nullable()->unique();
            $table->timestamp('confirm_expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('rejection_reason', 1000)->nullable();
            $table->text('admin_notes')->nullable();
            $table->foreignId('referrer_id')->nullable()->constrained('referrers')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['status', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_applications');
    }
};
