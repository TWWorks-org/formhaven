<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // When the account first proved its address. Unlike email_verified_at it stays set after an
            // email change, so the account keeps drawing its mail from the pool for existing accounts
            // (App\Support\AccountMail), which signups cannot spend.
            $table->timestamp('first_verified_at')->nullable();
        });

        DB::table('users')->whereNotNull('email_verified_at')->update(['first_verified_at' => DB::raw('email_verified_at')]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('first_verified_at'));
    }
};
