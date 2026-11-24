<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A form address an agent reserved without an account. It stores nothing a visitor
        // sends until the site owner opens the link, accepts the terms and activates it.
        Schema::create('form_claims', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 16)->unique();
            $table->string('name', 100);
            $table->string('site_url', 2048)->nullable();
            $table->string('redirect_url', 2048)->nullable();
            $table->unsignedSmallInteger('retention_days')->nullable();
            $table->string('client', 60)->nullable();
            $table->string('claim_token_hash', 64)->unique();
            $table->string('setup_key_hash', 64)->unique();
            $table->string('ip_hash', 64)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_claims');
    }
};
