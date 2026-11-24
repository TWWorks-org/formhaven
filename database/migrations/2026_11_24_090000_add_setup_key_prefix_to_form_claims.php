<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The first characters of the setup key, so the form's page can show which key is which.
        // Only the hash was stored before, so older reservations have none.
        Schema::table('form_claims', function (Blueprint $table) {
            $table->string('setup_key_prefix', 12)->nullable()->after('setup_key_hash');
        });
    }

    public function down(): void
    {
        Schema::table('form_claims', function (Blueprint $table) {
            $table->dropColumn('setup_key_prefix');
        });
    }
};
