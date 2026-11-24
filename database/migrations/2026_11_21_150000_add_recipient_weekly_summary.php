<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            // On by default, but it only sends while recipients_can_view is on too: the email's job is
            // the link to that page, and turning the page on is the owner's opt-in (2026-11-21).
            $table->boolean('recipients_weekly_summary')->default(true);
        });

        Schema::table('form_recipients', function (Blueprint $table) {
            // The recipient pressed "Stop the weekly count": the summary stops, the message emails do not.
            $table->timestamp('summary_stopped_at')->nullable();
            // Monday (Europe/Amsterdam) of the last week a summary was queued for, so a week is never sent twice.
            $table->date('summary_week')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('forms', fn (Blueprint $table) => $table->dropColumn('recipients_weekly_summary'));
        Schema::table('form_recipients', fn (Blueprint $table) => $table->dropColumn(['summary_stopped_at', 'summary_week']));
    }
};
