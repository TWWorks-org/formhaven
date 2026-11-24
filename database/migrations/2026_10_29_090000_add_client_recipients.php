<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extra addresses a form's submissions go to, usually the agency's client.
        // Nothing is sent to one until it confirms by clicking the link we email it.
        Schema::create('form_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['form_id', 'email']);
        });

        Schema::table('forms', function (Blueprint $table) {
            // Off: only the confirmed recipients get emails. With none confirmed, the owner still does.
            $table->boolean('notify_owner')->default(true);
            // Shown as the sender of the emails recipients get; the owner's name when empty.
            $table->string('sender_name', 60)->nullable();
        });

        Schema::table('submissions', function (Blueprint $table) {
            // Emails still to go out; a form that stores nothing wipes the answers when it reaches 0.
            $table->unsignedSmallInteger('emails_pending')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn('emails_pending');
        });

        Schema::table('forms', function (Blueprint $table) {
            $table->dropColumn(['notify_owner', 'sender_name']);
        });

        Schema::dropIfExists('form_recipients');
    }
};
