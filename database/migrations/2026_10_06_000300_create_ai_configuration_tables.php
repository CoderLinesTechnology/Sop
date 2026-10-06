<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Administrator-controlled configuration for the AI pipeline and the
     * document engine: workflows, versioned prompts, model prices,
     * formatting templates and country/institution requirement rules.
     */
    public function up(): void
    {
        Schema::create('ai_workflows', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('config');
            $table->foreignId('fallback_workflow_id')->nullable()->constrained('ai_workflows')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->string('prompt_key', 60)->index();
            $table->unsignedInteger('version');
            $table->string('label')->nullable();
            $table->text('description')->nullable();
            $table->longText('system_prompt');
            $table->longText('user_template')->nullable();
            $table->string('model', 80)->nullable();
            $table->string('reasoning_effort', 10)->nullable();
            $table->string('status', 20)->default('draft');
            // Only one active version per prompt key (NULLs are not unique in MySQL).
            $table->string('active_key', 60)->nullable()->storedAs("IF(`status` = 'active', `prompt_key`, NULL)")->unique();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('activated_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique(['prompt_key', 'version']);
        });

        Schema::create('ai_model_prices', function (Blueprint $table) {
            $table->id();
            $table->string('model', 80)->unique();
            $table->decimal('input_per_million', 10, 4);
            $table->decimal('cached_input_per_million', 10, 4)->nullable();
            $table->decimal('output_per_million', 10, 4);
            $table->decimal('web_search_per_call', 10, 6)->default(0);
            $table->string('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->string('description', 500)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->json('match_rules')->nullable();
            $table->string('page_size', 10)->default('A4');
            $table->decimal('margin_top_mm', 5, 1)->default(25.4);
            $table->decimal('margin_right_mm', 5, 1)->default(25.4);
            $table->decimal('margin_bottom_mm', 5, 1)->default(25.4);
            $table->decimal('margin_left_mm', 5, 1)->default(25.4);
            $table->string('font_family', 60)->default('Times New Roman');
            $table->decimal('font_size', 4, 1)->default(12);
            $table->decimal('line_spacing', 3, 2)->default(1.5);
            $table->decimal('paragraph_spacing_pt', 4, 1)->default(8);
            $table->decimal('first_line_indent_mm', 4, 1)->default(0);
            $table->string('text_align', 10)->default('left');
            $table->boolean('show_title')->default(true);
            $table->string('title_template')->nullable();
            $table->decimal('title_font_size', 4, 1)->default(14);
            $table->string('title_align', 10)->default('center');
            $table->decimal('heading_font_size', 4, 1)->default(12);
            $table->string('applicant_name_position', 20)->default('below_title');
            $table->string('header_text')->nullable();
            $table->string('footer_text')->nullable();
            $table->string('page_numbers', 20)->default('bottom_center');
            $table->string('page_number_format', 60)->default('{PAGE}');
            $table->string('date_format', 20)->nullable();
            $table->string('citation_style', 20)->default('none');
            $table->string('filename_pattern')->default('{applicant_name}_{document_type}');
            $table->boolean('include_name_in_filename')->default(true);
            $table->boolean('include_branding')->default(false);
            $table->timestamps();
        });

        Schema::create('requirement_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('scope', 20)->index();
            $table->char('country_code', 2)->nullable()->index();
            $table->string('institution_name')->nullable()->index();
            $table->string('institution_domain')->nullable();
            $table->string('programme_name')->nullable();
            $table->string('degree_level', 60)->nullable();
            $table->string('application_platform', 60)->nullable();
            $table->json('document_kinds')->nullable();
            $table->unsignedInteger('min_words')->nullable();
            $table->unsignedInteger('max_words')->nullable();
            $table->unsignedInteger('min_characters')->nullable();
            $table->unsignedInteger('max_characters')->nullable();
            $table->unsignedSmallInteger('max_pages')->nullable();
            $table->string('font_family', 60)->nullable();
            $table->decimal('font_size', 4, 1)->nullable();
            $table->decimal('margins_mm', 5, 1)->nullable();
            $table->decimal('line_spacing', 3, 2)->nullable();
            $table->string('page_size', 10)->nullable();
            $table->json('file_types')->nullable();
            $table->string('naming_convention')->nullable();
            $table->json('required_sections')->nullable();
            $table->json('prohibited_content')->nullable();
            $table->string('language_variant', 10)->nullable();
            $table->string('date_format', 20)->nullable();
            $table->text('special_instructions')->nullable();
            $table->string('submission_method')->nullable();
            $table->string('source_name')->nullable();
            $table->string('source_url', 1000)->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->foreignId('verified_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requirement_rules');
        Schema::dropIfExists('document_templates');
        Schema::dropIfExists('ai_model_prices');
        Schema::dropIfExists('prompt_versions');
        Schema::dropIfExists('ai_workflows');
    }
};
