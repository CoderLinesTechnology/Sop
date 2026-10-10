<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Outbox of public URLs to announce to IndexNow search engines (one row
     * per URL; a URL that changes again becomes pending again).
     */
    public function up(): void
    {
        Schema::create('search_pings', function (Blueprint $table) {
            $table->id();
            $table->string('url', 2000);
            $table->char('url_hash', 64)->unique();
            $table->string('status', 12)->default('pending'); // pending | sending | sent | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->char('claim', 32)->nullable()->index();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_pings');
    }
};
