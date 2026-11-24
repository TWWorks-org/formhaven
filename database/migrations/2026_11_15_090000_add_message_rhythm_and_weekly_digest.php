<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kept on the form, not read from the stored submissions: retention can be one day and a form
        // can keep nothing, and the quiet check still has to know how often messages usually come.
        Schema::table('forms', function (Blueprint $table) {
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('messages_total')->default(0);
            $table->unsignedInteger('typical_gap_hours')->nullable();
        });

        // The Monday summary: on unless the owner turns it off.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('weekly_digest')->default(true);
            $table->timestamp('digest_sent_at')->nullable();
        });

        // Start from what is still stored. Forms whose older rows were pruned start from what is left.
        foreach (DB::table('forms')->pluck('id') as $formId) {
            $times = DB::table('submissions')->where('form_id', $formId)->where('spam', false)
                ->orderBy('created_at')->pluck('created_at')->map(fn ($at) => Carbon::parse($at));

            if ($times->isEmpty()) {
                continue;
            }

            $gap = $times->count() > 1 ? (int) round($times->first()->diffInHours($times->last()) / ($times->count() - 1)) : null;

            DB::table('forms')->where('id', $formId)->update([
                'last_message_at' => $times->last(),
                'messages_total' => $times->count(),
                'typical_gap_hours' => $gap,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropColumn(['last_message_at', 'messages_total', 'typical_gap_hours']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['weekly_digest', 'digest_sent_at']);
        });
    }
};
