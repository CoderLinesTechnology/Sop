<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up questions written by the AI are stored as the label of the
 * customer's answer and can be up to 500 characters; a 255-character column
 * rejected the save, so the customer could not send their answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_answers', function (Blueprint $table) {
            $table->string('label', 500)->change();
        });
    }

    public function down(): void
    {
        DB::table('order_answers')->whereRaw('CHAR_LENGTH(label) > 255')->update(['label' => DB::raw('LEFT(label, 255)')]);

        Schema::table('order_answers', function (Blueprint $table) {
            $table->string('label', 255)->change();
        });
    }
};
