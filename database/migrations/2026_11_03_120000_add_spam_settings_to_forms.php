<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            // On: a post without a solved bot-check puzzle (from /bot-check.js) goes to spam.
            $table->boolean('bot_check')->default(false);
            // Words or phrases the owner never wants in their inbox; a match goes to spam.
            $table->json('spam_words')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('forms', fn (Blueprint $table) => $table->dropColumn(['bot_check', 'spam_words']));
    }
};
