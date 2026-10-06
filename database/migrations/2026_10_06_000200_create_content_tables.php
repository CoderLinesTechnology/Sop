<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Site settings and CMS content: pages, resource articles, testimonials and
     * newsletter subscribers.
     */
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->string('group', 40)->index();
            $table->json('value')->nullable();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('title');
            $table->string('kind', 20)->default('standard');
            $table->string('excerpt', 500)->nullable();
            $table->longText('body')->nullable();
            $table->json('sections')->nullable();
            $table->string('hero_image_path')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->string('og_image_path')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('article_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->string('icon', 40)->default('book');
            $table->string('description', 500)->nullable();
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('slug', 160)->unique();
            $table->string('excerpt', 500)->nullable();
            $table->longText('body')->nullable();
            $table->string('cover_image_path')->nullable();
            $table->string('cover_image_alt')->nullable();
            $table->string('author_name', 120)->nullable();
            $table->unsignedTinyInteger('reading_minutes')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable()->index();
            $table->integer('display_order')->default(0);
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->timestamps();

            $table->index(['is_published', 'is_featured', 'display_order']);
        });

        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->text('quote');
            $table->string('author_name', 120);
            $table->string('author_detail', 160)->nullable();
            $table->string('avatar_path')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->boolean('is_published')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('status', 20)->default('pending')->index();
            $table->char('confirm_token_hash', 64)->nullable()->unique();
            $table->char('unsubscribe_token_hash', 64)->nullable()->unique();
            $table->string('source', 40)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
        Schema::dropIfExists('testimonials');
        Schema::dropIfExists('articles');
        Schema::dropIfExists('article_categories');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('site_settings');
    }
};
