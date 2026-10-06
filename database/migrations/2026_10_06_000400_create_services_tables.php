<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The configuration-driven service catalogue. Every service, its price,
     * questions, upload slots, AI workflow and revision policy is data that
     * administrators manage; nothing here is hard-coded in the frontend.
     */
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('slug', 160)->unique();
            $table->string('document_kind', 60)->default('general_essay');
            $table->string('short_description', 500);
            $table->text('description')->nullable();
            $table->json('card_features')->nullable();
            $table->string('badge', 40)->nullable();
            $table->string('icon', 40)->default('document');
            $table->string('icon_color', 20)->default('green');
            $table->string('image_path')->nullable();
            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('compare_at_price')->nullable();
            $table->char('currency', 3);
            $table->string('promo_label', 80)->nullable();
            $table->unsignedSmallInteger('delivery_min_minutes')->nullable();
            $table->unsignedSmallInteger('delivery_max_minutes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->integer('display_order')->default(0);
            $table->foreignId('ai_workflow_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('document_template_id')->nullable()->constrained()->nullOnDelete();
            $table->text('writing_guidance')->nullable();
            $table->unsignedInteger('default_word_limit')->nullable();
            $table->text('order_instructions')->nullable();
            $table->unsignedTinyInteger('revisions_included')->default(1);
            $table->unsignedSmallInteger('revision_window_days')->default(14);
            $table->unsignedBigInteger('revision_fee')->nullable();
            $table->string('revision_mode', 10)->default('ai');
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->string('og_image_path')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['is_active', 'display_order']);
        });

        Schema::create('service_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('label');
            $table->string('type', 30);
            $table->string('section', 30)->default('application');
            $table->string('requirement', 20)->default('optional');
            $table->text('help_text')->nullable();
            $table->string('placeholder')->nullable();
            $table->json('options')->nullable();
            $table->json('validation')->nullable();
            $table->string('maps_to', 40)->nullable();
            $table->text('ai_hint')->nullable();
            $table->string('optional_when_upload', 60)->nullable();
            $table->json('show_when')->nullable();
            $table->string('width', 10)->default('full');
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['service_id', 'key']);
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('question', 500);
            $table->text('answer');
            $table->string('scope', 20)->default('general');
            $table->foreignId('service_id')->nullable()->constrained()->cascadeOnDelete();
            $table->integer('display_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->index(['scope', 'is_published', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('service_fields');
        Schema::dropIfExists('services');
    }
};
