<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Apps that registered themselves (OAuth Dynamic Client Registration) to sign people in to /mcp.
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('name', 255);
            $table->json('redirect_uris');
            $table->timestamps();
        });

        // One-time codes between the consent screen and the token request. Kept a day, then pruned.
        Schema::create('oauth_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code_hash', 64)->unique();
            $table->string('client_id', 40);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('redirect_uri', 1024);
            $table->string('code_challenge', 128);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->unsignedBigInteger('access_token_id')->nullable();
            $table->timestamps();
        });

        // Long-lived tokens that buy a new hour-long access token. Rotated on every use.
        Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->string('client_id', 40);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('access_token_id')->nullable()->index();
            $table->unsignedBigInteger('code_id')->nullable()->index();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_refresh_tokens');
        Schema::dropIfExists('oauth_codes');
        Schema::dropIfExists('oauth_clients');
    }
};
