<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presentation options for document templates (used by the "Classic
 * Professional CV" layout): heading colour and capitals, the colour of the
 * rule under CV section headings, the title/name in capitals, the contact
 * block's layout and link colour, styled CV entry lines and the alignment of
 * bullet points.
 *
 * Every default reproduces the previous look (black, no capitals, one-line
 * contact line, plain entry lines, bullets aligned like the body text), so
 * existing templates render exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->boolean('title_uppercase')->default(false);
            $table->string('heading_color', 7)->nullable();
            $table->boolean('heading_uppercase')->default(false);
            $table->string('heading_rule_color', 7)->nullable();
            $table->string('contact_layout', 20)->default('single_line');
            $table->string('link_color', 7)->nullable();
            $table->boolean('entry_line_style')->default(false);
            $table->string('entry_detail_color', 7)->nullable();
            $table->string('bullet_align', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn([
                'title_uppercase', 'heading_color', 'heading_uppercase', 'heading_rule_color', 'contact_layout',
                'link_color', 'entry_line_style', 'entry_detail_color', 'bullet_align',
            ]);
        });
    }
};
