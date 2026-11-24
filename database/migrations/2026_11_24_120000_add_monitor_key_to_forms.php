<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            // The owner's DidMyFormSend heartbeat key, encrypted with the app key (the model's
            // "encrypted" cast). Null: nothing is ever reported to anyone (app/Support/DidMyFormSend.php).
            $table->text('monitor_key')->nullable();
            // When DidMyFormSend last answered 401 or 410 to this key; cleared by an accepted report or a new key.
            $table->timestamp('monitor_refused_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('forms', fn (Blueprint $table) => $table->dropColumn(['monitor_key', 'monitor_refused_at']));
    }
};
