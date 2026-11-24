<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One email that our mail provider would not deliver. A row exists exactly as long as the
        // message is undelivered: Send again deletes it, and the cascades take it with a pruned
        // or deleted submission, a removed recipient and a deleted form.
        Schema::create('delivery_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            // Null for a confirmation email, which belongs to no submission.
            $table->foreignId('submission_id')->nullable()->constrained()->cascadeOnDelete();
            // Null when the address is the account owner's.
            $table->foreignId('form_recipient_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('kind', 20);      // bounced | suppressed | error
            $table->string('error', 500);    // the provider's own text, for us
            $table->timestamps();
            $table->index('form_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_failures');
    }
};
