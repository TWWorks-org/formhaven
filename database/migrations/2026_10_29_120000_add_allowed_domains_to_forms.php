<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            // The sites a form accepts submissions from; null or empty means any site.
            $table->json('allowed_domains')->nullable();
        });

        Schema::table('submissions', function (Blueprint $table) {
            // Why a row is spam ("honeypot", "not_allowed:<host>", "no_origin"), so the owner can tell a bot from a wrong setting.
            $table->string('spam_reason', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('forms', fn (Blueprint $table) => $table->dropColumn('allowed_domains'));
        Schema::table('submissions', fn (Blueprint $table) => $table->dropColumn('spam_reason'));
    }
};
