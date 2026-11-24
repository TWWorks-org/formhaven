<?php

namespace App\Actions;

use App\Models\Form;
use App\Models\User;
use App\Support\Bill;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** One place that creates forms, so the dashboard, the API and MCP enforce the same rules and limits. */
class CreateForm
{
    public const RULES = [
        'name' => ['required', 'string', 'max:100'],
        'site_url' => ['nullable', 'url:https,http', 'max:2048'],
        'redirect_url' => ['nullable', 'url:https,http', 'max:2048'],
        'retention_days' => ['nullable', 'integer', 'min:1', 'max:365'],
    ];

    public const SCOPED_KEY_REFUSAL = 'This key belongs to one form and cannot create others. Start another form with POST /api/v1/start, or use an API key from the dashboard.';

    /** $publicId: a form address reserved earlier by the agent start flow (StartForm). */
    public function handle(User $user, array $input, ?string $publicId = null): Form
    {
        $data = Validator::make($input, self::RULES)->validate();

        if (! $user->canCreateForm()) {
            $plan = $user->plan();

            throw ValidationException::withMessages(['name' => $plan
                ? "Your trial includes {$plan['forms']} forms. Subscribe on the Billing page to add more: the first ".config('billing.per_form.full_price_forms').' forms are '.Bill::format(config('billing.per_form.monthly_eur')).' each a month, every form from the '.Bill::firstLowerForm().' '.Bill::format(config('billing.per_form.lower_monthly_eur')).'.'
                : 'Your trial has ended. Subscribe on the Billing page to create forms.']);
        }

        // public_id stays out of $fillable: only a reservation we made ourselves can set it.
        $form = $user->forms()->make(array_filter($data, fn ($v) => $v !== null));
        if ($publicId !== null) {
            $form->public_id = $publicId;
        }
        $form->save();

        // refresh() loads the database defaults so callers never see null for them.
        return $form->refresh();
    }
}
