<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->string('webhook_url', 2048)->nullable();
            // Signs each delivery so the receiver can check it came from us.
            $table->string('webhook_secret', 64)->nullable();
            // The last delivery, shown on the form page: a status code, or the reason it failed.
            $table->unsignedSmallInteger('webhook_last_status')->nullable();
            $table->string('webhook_last_error', 500)->nullable();
            $table->timestamp('webhook_last_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropColumn(['webhook_url', 'webhook_secret', 'webhook_last_status', 'webhook_last_error', 'webhook_last_at']);
        });
    }
};
