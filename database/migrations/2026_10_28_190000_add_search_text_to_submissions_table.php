<?php

use App\Models\Submission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            // The answers in lowercase without accents, so "chloe" finds "Chloé":
            // SQLite's LIKE ignores case for ASCII only and never ignores accents.
            $table->text('search_text')->nullable();
        });

        // Set directly: saveQuietly() skips the saving hook that fills it, and save() writes nothing unchanged.
        Submission::query()->eachById(fn (Submission $submission) => $submission->newQuery()->whereKey($submission->id)
            ->update(['search_text' => Submission::searchable(collect($submission->data)->flatten()->implode("\n"))]));
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn('search_text');
        });
    }
};
