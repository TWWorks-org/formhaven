<?php

namespace App\Actions;

use App\Models\Form;
use App\Models\FormClaim;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a form with its submissions, recipients and delivery records (foreign keys
 * cascade), for the dashboard, REST and MCP alike. The bill follows the number of forms,
 * so the Form model's deleted hook lowers the Stripe quantity.
 *
 * The caller must repeat the form's name: an agent mixing up two ids, or a mis-click,
 * cannot wipe a client's messages.
 */
class DeleteForm
{
    public const RULES = ['confirm' => ['required', 'string']];

    /** @return int the number of submissions deleted with it */
    public function handle(Form $form, ?string $confirm): int
    {
        if (trim((string) $confirm) !== $form->name) {
            throw ValidationException::withMessages(['confirm' => 'Type the form\'s name exactly ("'.$form->name.'") to delete it.']);
        }

        $submissions = $form->submissions()->count();

        // A reservation for this address would otherwise report the deleted form as still waiting.
        FormClaim::where('public_id', $form->public_id)->delete();
        $form->delete();

        return $submissions;
    }

    public static function summary(string $name, int $submissions, bool $paying): string
    {
        return "Deleted {$name} and its {$submissions} stored ".($submissions === 1 ? 'submission' : 'submissions').'. Its address no longer accepts messages'.($paying ? ', and your bill counts one form fewer from today.' : '.');
    }
}
