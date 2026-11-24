<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the account holder accepted the terms: the proof that they were offered
        // before the contract was made (art. 6:234 BW). Null for accounts made before this.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable();
        });

        // Notices of illegal content or abuse under DSA art. 16, kept so each one can be
        // answered and the decision shown later.
        Schema::create('abuse_reports', function (Blueprint $table) {
            $table->id();
            $table->string('url', 2000);
            $table->text('reason');
            $table->string('name');
            $table->string('email');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abuse_reports');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('terms_accepted_at'));
    }
};
