<?php

namespace App\Http\Controllers;

use App\Models\Form;
use Illuminate\Support\Str;

/**
 * A form's submissions as CSV, spam left out, oldest first: one column per field in the
 * order fields first appeared, so an agency can hand a client a spreadsheet.
 */
class ExportSubmissionsController extends Controller
{
    public function __invoke(Form $form)
    {
        abort_unless($form->user_id === auth()->id(), 404);

        $submissions = $form->submissions()->where('spam', false)->oldest()->get()->reject->isForgotten();
        $fields = $submissions->flatMap(fn ($s) => array_keys($s->data))->unique()->values()->all();

        $filename = Str::slug($form->name).'-submissions-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($submissions, $fields) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // Excel reads the file as UTF-8 only with this mark.
            // Field names are typed by visitors as much as answers are, so they get the same guard.
            fputcsv($out, ['Received', ...array_map(self::cell(...), $fields)], escape: '');

            foreach ($submissions as $submission) {
                fputcsv($out, [
                    $submission->created_at->toDateTimeString(),
                    ...array_map(fn ($field) => self::cell($submission->data[$field] ?? ''), $fields),
                ], escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /** A visitor's answer is data, never a formula: a leading = + - @ would run when the file is opened. */
    private static function cell(mixed $answer): string
    {
        $text = is_array($answer) ? implode(', ', $answer) : (string) $answer;

        return preg_match('/^[=+\-@\t\r]/', $text) ? "'".$text : $text;
    }
}
