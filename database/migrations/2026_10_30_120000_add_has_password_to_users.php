<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Whether the person chose a password themselves. An account made through Google has a
        // random one nobody knows, so deleting it is confirmed by an emailed link instead.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('has_password')->default(true);
        });

        // A Google-linked account may also have a password, but there is no telling which: the
        // emailed link is the safe side, and resetting the password sets the flag again.
        DB::table('users')->whereNotNull('google_id')->update(['has_password' => false]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('has_password'));
    }
};
