<?php

namespace App\Mcp\Tools;

use App\Support\Bill;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[\Laravel\Mcp\Server\Tools\Annotations\IsReadOnly]
#[\Laravel\Mcp\Server\Attributes\Title('List forms')]
#[Name('list_forms')]
#[Description('List every form on the account with its id, endpoint URL, number of real (non-spam) submissions and health: what stops its messages reaching people and how to fix it. Plus the account limits and how it is billed.')]
class ListFormsTool extends Tool
{
    /** The attribute above on the hosted service; a self-hosted install has no limits or bill. */
    public function description(): string
    {
        return \App\Support\Mode::hosted()
            ? parent::description()
            : 'List every form on the account with its id, endpoint URL, number of real (non-spam) submissions and health: what stops its messages reaching people and how to fix it.';
    }

    public function handle(Request $request): Response
    {
        $user = $request->user();
        $plan = $user->plan();

        return Response::json([
            'plan' => $plan && $plan['key'] === 'self_hosted' ? [
                'name' => $plan['name'],
                'forms_limit' => 'unlimited',
                'submissions_this_month' => $user->submissionsThisMonth(),
                'submissions_included' => 'unlimited',
                'billing' => 'Self-hosted install: no bill and no limits.',
            ] : ($plan ? [
                'name' => $plan['name'],
                'forms_limit' => $plan['forms'] ?? 'unlimited',
                'submissions_this_month' => $user->submissionsThisMonth(),
                'submissions_included' => $plan['submissions'],
                'billing' => match ($plan['key']) {
                    'paid' => 'Per form: '.$user->formUnits().' units, EUR '.Bill::format(Bill::monthly($user->formUnits()), '').' a month on a monthly plan or EUR '.Bill::format(Bill::yearly($user->formUnits()), '').' a year on a yearly one (never fewer than '.config('billing.per_form.min_forms').' units). Each of the first '.config('billing.per_form.full_price_forms').' units is EUR '.Bill::format(config('billing.per_form.monthly_eur'), '').' a month, each from the '.Bill::firstLowerForm().' EUR '.Bill::format(config('billing.per_form.lower_monthly_eur'), '').'. Each new form adds one unit and '.number_format(config('billing.per_form.submissions')).' submissions, whatever it costs; each 1,000 submissions past submissions_included is EUR '.Bill::format(config('billing.per_form.extra_eur'), '').', added to the next invoice once the month ends. Emails never stop.',
                    'trial' => 'Free trial: up to '.$plan['forms'].' forms. Past submissions_included plus '.(int) round(config('billing.email_grace') * 100).'%, messages are saved but not emailed until the owner subscribes at '.route('billing').'.',
                    default => 'Owner account: no bill.',
                },
            ] : 'No active subscription: forms are paused. The owner can subscribe at '.route('billing')),
            'forms' => $user->formsForKey()
                ->with(['recipients', 'deliveryFailures'])
                ->withCount(['submissions' => fn ($q) => $q->where('spam', false)])
                ->latest()->get()->map->toApi(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
