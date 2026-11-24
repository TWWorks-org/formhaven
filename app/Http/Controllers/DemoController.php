<?php

namespace App\Http\Controllers;

use App\Models\Form;
use Illuminate\Http\Request;

/**
 * The live form on the landing page. It runs the same checks a real form does
 * and answers with each step, but stores and emails nothing: a demo must not
 * fill anyone's inbox or the shared daily email quota.
 */
class DemoController extends Controller
{
    public function __invoke(Request $request)
    {
        $fields = collect($request->except(['_token']))
            ->reject(fn ($value, $key) => str_starts_with((string) $key, '_'))
            ->filter(fn ($value) => filled($value));

        if ($fields->isEmpty()) {
            return response()->json(['ok' => false, 'message' => 'Type something in at least one field first.'], 422);
        }

        $spam = filled($request->input('_gotcha'));
        $email = filter_var($request->input('email'), FILTER_VALIDATE_EMAIL) ?: null;

        $steps = [
            ['tag' => 'received', 'text' => $fields->count().' '.str('field')->plural($fields->count()).' received: '.$fields->keys()->implode(', ')],
            $spam
                ? ['tag' => 'spam', 'text' => 'Spam trap filled in: kept in the spam list, not emailed']
                : ['tag' => 'spam', 'text' => 'Spam trap empty, under the rate limit: passed'],
            ['tag' => 'stored', 'text' => 'A real form saves it on our EU servers. This demo saves nothing.'],
        ];

        if (! $spam) {
            $steps[] = ['tag' => 'email', 'text' => $email
                ? "A real form emails it to the owner. Pressing Reply goes to {$email}."
                : 'A real form emails it to the owner.'];
        }

        $steps[] = ['tag' => 'kept', 'text' => 'Deleted automatically after the days the owner sets, '.Form::DEFAULT_RETENTION_DAYS.' by default'];

        return response()->json(['ok' => true, 'steps' => $steps]);
    }
}
