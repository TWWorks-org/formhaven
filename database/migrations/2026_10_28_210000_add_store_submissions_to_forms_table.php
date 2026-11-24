<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            // Off: answers are emailed and then wiped. The row stays, empty, because it is what the monthly limit counts.
            $table->boolean('store_submissions')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropColumn('store_submissions');
        });
    }
};
