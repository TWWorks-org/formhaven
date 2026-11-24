<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Real submissions per account per month. Counting submission rows is not enough: a form
// that keeps messages for a few days has pruned most of them by the 1st, when the month is
// billed. charged_units and charged_at make the monthly extra-usage charge run once.
// peak_units is the most form units the account had that month, so deleting forms late in
// the month does not shrink that month's pool after its submissions arrived.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7); // YYYY-MM
            $table->unsignedInteger('submissions')->default(0);
            $table->unsignedInteger('peak_units')->default(0);
            $table->unsignedInteger('charged_units')->nullable();
            $table->timestamp('charged_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_usage');
    }
};
