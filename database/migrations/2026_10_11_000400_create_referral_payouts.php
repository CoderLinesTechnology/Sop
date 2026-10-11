<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Referral payouts: a referrer requests their whole available balance;
     * the ledger entries it covers are linked to the request (payout_id) and
     * stamped paid when an administrator records the transfer, or released
     * again when the request is rejected or cancelled.
     *
     * "One open request per referrer" is enforced by the database: the stored
     * generated column holds the referrer id only while the request is open,
     * and NULLs never collide in the unique index.
     *
     * Payment details (mobile money or bank) are kept encrypted on the
     * referrer, with a pending change that applies only after the referrer
     * confirms it from their email. Every timestamp is nullable (MariaDB).
     */
    public function up(): void
    {
        Schema::create('referral_payouts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('referrer_id')->constrained('referrers')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 20);
            $table->unsignedBigInteger('open_referrer_id')->storedAs("CASE WHEN status = 'requested' THEN referrer_id END");
            $table->text('payment_details')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('payout_reference', 100)->nullable();
            $table->string('rejection_reason', 1000)->nullable();
            $table->timestamps();

            $table->unique('open_referrer_id');
            $table->index(['referrer_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('referral_commissions', function (Blueprint $table) {
            $table->foreignId('payout_id')->nullable()->after('amount')->constrained('referral_payouts')->restrictOnDelete();
            // Entries of one payout (stamp / release). Explicit: only InnoDB indexes a foreign key by itself.
            $table->index('payout_id');
        });

        Schema::table('referrers', function (Blueprint $table) {
            $table->text('payment_details')->nullable()->after('notes');
            $table->timestamp('payment_details_changed_at')->nullable()->after('payment_details');
            $table->text('pending_payment_details')->nullable()->after('payment_details_changed_at');
            $table->char('pending_details_token_hash', 64)->nullable()->unique()->after('pending_payment_details');
            $table->timestamp('pending_details_expires_at')->nullable()->after('pending_details_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('referrers', function (Blueprint $table) {
            $table->dropUnique(['pending_details_token_hash']);
            $table->dropColumn(['payment_details', 'payment_details_changed_at', 'pending_payment_details', 'pending_details_token_hash', 'pending_details_expires_at']);
        });

        Schema::table('referral_commissions', function (Blueprint $table) {
            $table->dropForeign(['payout_id']);
            $table->dropIndex(['payout_id']);
            $table->dropColumn('payout_id');
        });

        Schema::dropIfExists('referral_payouts');
    }
};
