<?php

namespace App\Actions;

use App\Models\Form;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A form's spam settings (the bot check and its spam words), in one place, so the
 * dashboard, the REST API and MCP accept and store exactly the same thing.
 */
class FormSpamSettings
{
    public const MAX_WORDS = 50;

    public const MAX_WORD_LENGTH = 60;

    public const RULES = [
        'bot_check' => ['sometimes', 'boolean'],
        // A list, or text with one entry per line (commas work too), as the dashboard sends it.
        'spam_words' => ['sometimes', 'nullable'],
    ];

    /** @throws ValidationException */
    public function update(Form $form, array $input): Form
    {
        $data = Validator::make($input, self::RULES)->validate();

        if (array_key_exists('spam_words', $data)) {
            $data['spam_words'] = self::words($data['spam_words']) ?: null;
        }

        $form->update($data);

        return $form;
    }

    /**
     * What the owner typed as the stored list: trimmed, lowercase, duplicates dropped.
     *
     * @throws ValidationException under the key "spam_words"
     */
    public static function words(mixed $typed): array
    {
        $entries = match (true) {
            $typed === null => [],
            is_string($typed) => preg_split('/[\r\n,]+/', $typed),
            is_array($typed) && array_is_list($typed) => $typed,
            default => throw ValidationException::withMessages(['spam_words' => 'Send spam_words as a list of words, or text with one per line.']),
        };

        $words = [];
        foreach ($entries as $entry) {
            if (! is_string($entry)) {
                throw ValidationException::withMessages(['spam_words' => 'Every spam word must be text.']);
            }
            $word = Str::lower(trim(preg_replace('/\s+/', ' ', $entry)));
            if ($word === '') {
                continue;
            }
            if (mb_strlen($word) < 2 || mb_strlen($word) > self::MAX_WORD_LENGTH) {
                throw ValidationException::withMessages(['spam_words' => '"'.Str::limit($word, 60).'" must be 2 to '.self::MAX_WORD_LENGTH.' characters.']);
            }
            $words[$word] = true;
        }

        if (count($words) > self::MAX_WORDS) {
            throw ValidationException::withMessages(['spam_words' => 'List up to '.self::MAX_WORDS.' spam words and blocked senders together.']);
        }

        return array_keys($words);
    }
}
