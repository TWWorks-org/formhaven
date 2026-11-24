<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            // On: each confirmed recipient's email links to a read-only page of this form's messages.
            // Off by default, because a link in an email can be forwarded.
            $table->boolean('recipients_can_view')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('forms', fn (Blueprint $table) => $table->dropColumn('recipients_can_view'));
    }
};
