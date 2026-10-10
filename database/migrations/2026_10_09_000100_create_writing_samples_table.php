<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Example documents administrators upload so the writing stages can study
     * the structure, tone and specificity of strong applications. Only the
     * (redacted, encrypted) text is kept; the uploaded file is discarded.
     * Each AI job records which samples it was shown.
     */
    public function up(): void
    {
        Schema::create('writing_samples', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->string('document_kind', 40);
            $table->string('degree_level', 60)->nullable();
            $table->string('field_of_study', 120)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->text('notes')->nullable();
            $table->longText('content');
            $table->unsignedInteger('word_count')->default(0);
            $table->string('source', 10);
            $table->json('redactions')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('rights_confirmed_at')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['document_kind', 'is_active']);
        });

        Schema::table('ai_jobs', function (Blueprint $table) {
            // Appended (no AFTER) so MySQL/MariaDB can add it instantly, without a table rebuild.
            $table->json('writing_sample_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_jobs', function (Blueprint $table) {
            $table->dropColumn('writing_sample_ids');
        });

        Schema::dropIfExists('writing_samples');
    }
};
