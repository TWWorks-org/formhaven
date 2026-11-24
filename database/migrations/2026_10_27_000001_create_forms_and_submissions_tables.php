<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('public_id', 32)->unique();
            $table->string('name');
            $table->string('redirect_url', 2048)->nullable();
            $table->unsignedSmallInteger('retention_days')->default(30);
            $table->timestamps();
        });

        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->json('data');
            $table->boolean('spam')->default(false);
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('referer', 2048)->nullable();
            $table->timestamps();

            $table->index(['form_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
        Schema::dropIfExists('forms');
    }
};
