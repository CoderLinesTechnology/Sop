<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Runtime records of the AI pipeline: jobs, step checkpoints, usage and
     * cost, the research dossier, the requirement verification log and
     * quality reviews. Every generation is traceable to the workflow, prompt
     * versions and models that produced it.
     */
    public function up(): void
    {
        Schema::create('ai_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('revision_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 30);
            $table->string('dedupe_key', 100)->unique();
            $table->string('status', 30)->index();
            $table->string('current_stage', 40)->nullable();
            $table->foreignId('ai_workflow_id')->nullable()->constrained()->nullOnDelete();
            $table->json('workflow_snapshot');
            $table->json('prompt_versions')->nullable();
            $table->string('provider', 20);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('total_input_tokens')->default(0);
            $table->unsignedBigInteger('total_cached_tokens')->default(0);
            $table->unsignedBigInteger('total_output_tokens')->default(0);
            $table->unsignedBigInteger('total_reasoning_tokens')->default(0);
            $table->unsignedInteger('llm_calls')->default(0);
            $table->unsignedInteger('search_calls')->default(0);
            $table->unsignedTinyInteger('refinement_rounds')->default(0);
            $table->decimal('total_cost_usd', 12, 6)->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->string('last_error_code', 60)->nullable();
            $table->text('last_error_message')->nullable();
            $table->boolean('used_fallback')->default(false);
            $table->timestamps();

            $table->index(['order_id', 'kind']);
        });

        Schema::create('ai_job_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_job_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 40);
            $table->unsignedSmallInteger('sequence');
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->string('status', 20);
            $table->string('model', 80)->nullable();
            $table->foreignId('prompt_version_id')->nullable()->constrained()->nullOnDelete();
            $table->json('input_summary')->nullable();
            $table->json('output')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->timestamps();

            $table->index(['ai_job_id', 'stage', 'status']);
        });

        Schema::create('ai_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_job_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('ai_job_step_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20);
            $table->string('model', 80)->index();
            $table->string('stage', 40);
            $table->foreignId('prompt_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('response_id', 100)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cached_input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->unsignedSmallInteger('search_calls')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->decimal('estimated_cost_usd', 12, 6)->default(0);
            $table->string('status', 20);
            $table->string('error_type', 60)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('quality_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('round');
            $table->json('scores');
            $table->decimal('overall_score', 4, 2);
            $table->decimal('threshold', 4, 2);
            $table->boolean('passed');
            $table->boolean('answers_prompt')->nullable();
            $table->json('issues')->nullable();
            $table->text('instructions')->nullable();
            $table->string('reviewer', 20)->default('ai');
            $table->string('model', 80)->nullable();
            $table->timestamps();
        });

        Schema::create('research_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_job_id')->nullable()->constrained()->nullOnDelete();
            $table->string('url', 2048);
            $table->char('url_hash', 64);
            $table->string('domain')->index();
            $table->string('title')->nullable();
            $table->string('source_type', 40);
            $table->boolean('is_official')->default(false);
            $table->unsignedTinyInteger('authority_rank')->default(9);
            $table->timestamp('retrieved_at')->nullable();
            $table->string('fetch_status', 20)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'url_hash']);
        });

        Schema::create('research_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('research_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('claim_key', 20);
            $table->text('claim');
            $table->string('category', 40);
            $table->text('supporting_quote')->nullable();
            $table->decimal('confidence', 3, 2)->nullable();
            $table->text('relevance')->nullable();
            $table->decimal('relevance_score', 3, 2)->nullable();
            $table->string('verification_status', 30);
            $table->string('verification_method', 40)->nullable();
            $table->text('verification_notes')->nullable();
            $table->boolean('safe_to_use')->default(false);
            $table->string('conflict_group', 40)->nullable();
            $table->boolean('used_in_document')->default(false);
            $table->timestamps();

            $table->index(['order_id', 'safe_to_use']);
        });

        Schema::create('order_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_job_id')->nullable()->constrained()->nullOnDelete();
            $table->json('resolved');
            $table->json('sources')->nullable();
            $table->json('conflicts')->nullable();
            $table->json('applied_rule_ids')->nullable();
            $table->foreignId('document_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('language_variant', 10)->nullable();
            $table->unsignedInteger('max_words')->nullable();
            $table->unsignedInteger('max_characters')->nullable();
            $table->unsignedSmallInteger('max_pages')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_requirements');
        Schema::dropIfExists('research_claims');
        Schema::dropIfExists('research_sources');
        Schema::dropIfExists('quality_reviews');
        Schema::dropIfExists('ai_usages');
        Schema::dropIfExists('ai_job_steps');
        Schema::dropIfExists('ai_jobs');
    }
};
