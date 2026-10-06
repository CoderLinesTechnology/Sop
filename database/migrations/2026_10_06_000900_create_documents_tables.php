<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Generated documents and their versions. Each version stores the
     * structured DocumentModel from which both the PDF and DOCX are rendered,
     * plus the results of file quality checks.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 60);
            $table->string('title');
            $table->unsignedBigInteger('current_version_id')->nullable()->index();
            $table->string('status', 20)->default('in_progress');
            $table->timestamps();
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('revision_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_job_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('source', 20);
            $table->string('status', 20)->default('draft');
            $table->string('title')->nullable();
            $table->json('content')->nullable();
            $table->longText('plain_text')->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedInteger('char_count')->default(0);
            $table->unsignedInteger('char_count_no_spaces')->default(0);
            $table->unsignedSmallInteger('page_count')->nullable();
            $table->string('language_variant', 10)->nullable();
            $table->foreignId('document_template_id')->nullable()->constrained()->nullOnDelete();
            $table->json('template_snapshot')->nullable();
            $table->json('requirements_snapshot')->nullable();
            $table->decimal('quality_score', 4, 2)->nullable();
            $table->string('files_disk', 30)->nullable();
            $table->boolean('files_encrypted')->default(true);
            $table->string('pdf_path')->nullable();
            $table->unsignedBigInteger('pdf_size')->nullable();
            $table->char('pdf_sha256', 64)->nullable();
            $table->string('docx_path')->nullable();
            $table->unsignedBigInteger('docx_size')->nullable();
            $table->char('docx_sha256', 64)->nullable();
            $table->string('pdf_filename')->nullable();
            $table->string('docx_filename')->nullable();
            $table->string('qa_status', 20)->nullable();
            $table->json('qa_results')->nullable();
            $table->timestamp('rendered_at')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
    }
};
