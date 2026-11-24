<?php

namespace App\Support;

use App\Models\AbuseReport;
use App\Models\DeliveryFailure;
use App\Models\Form;
use App\Models\FormClaim;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The numbers on the owner's admin page, and the agent-start numbers `agents:report` prints, from one
 * place so the two never disagree. Outside accounts only, unless a method says otherwise: the owner's
 * own account (OWNER_EMAILS) runs the forms on the other sites and would make every count look busier.
 * Nothing here reads what a visitor typed.
 */
class AdminStats
{
    /** Every account, newest first, with the facts the accounts table shows. */
    public function accounts(): Collection
    {
        $since = now()->subDays(30);

        return User::query()
            ->with('subscriptions')
            ->withCount('forms')
            ->latest('id')
            ->get()
            ->map(function (User $user) use ($since) {
                $formIds = $user->forms()->pluck('id');
                $real = Submission::whereIn('form_id', $formIds)->where('spam', false);

                return (object) [
                    'user' => $user,
                    'status' => $this->status($user),
                    'paying' => $this->isPaying($user),
                    'owner' => $user->isOwner(),
                    'mrr' => $this->monthlyEuros($user),
                    'forms' => $user->forms_count,
                    'messages_30d' => (clone $real)->where('created_at', '>=', $since)->count(),
                    'last_message_at' => (clone $real)->max('created_at'),
                    'failures_28d' => DeliveryFailure::whereIn('form_id', $formIds)->where('created_at', '>=', now()->subDays(28))->count(),
                ];
            });
    }

    /** The headline row: outside accounts only. */
    public function totals(Collection $accounts): array
    {
        $outside = $accounts->reject->owner;

        return [
            'accounts' => $outside->count(),
            'new_7d' => $outside->filter(fn ($a) => $a->user->created_at >= now()->subDays(7))->count(),
            'new_28d' => $outside->filter(fn ($a) => $a->user->created_at >= now()->subDays(28))->count(),
            'trialing' => $outside->filter(fn ($a) => ! $a->paying && $a->user->onGenericTrial())->count(),
            'paying' => $outside->where('paying', true)->count(),
            'mrr' => (int) round($outside->sum('mrr')),
        ];
    }

    /** Signed up, then each step a customer needs before they pay. Outside accounts only. */
    public function funnel(Collection $accounts): array
    {
        $outside = $accounts->reject->owner;

        return [
            'Signed up' => $outside->count(),
            'Confirmed their email' => $outside->filter(fn ($a) => $a->user->hasVerifiedEmail())->count(),
            'Made a form' => $outside->filter(fn ($a) => $a->forms > 0)->count(),
            'Got a real message' => $outside->filter(fn ($a) => $a->last_message_at !== null)->count(),
        ] + (Mode::hosted() ? ['Paying' => $outside->where('paying', true)->count()] : []);
    }

    /** Real messages and caught spam over the last $days days, on outside accounts' forms. */
    public function messages(int $days): array
    {
        $forms = Form::whereNotIn('user_id', $this->ownerIds())->select('id');
        $recent = Submission::whereIn('form_id', $forms)->where('created_at', '>=', now()->subDays($days));

        return ['real' => (clone $recent)->where('spam', false)->count(), 'spam' => (clone $recent)->where('spam', true)->count()];
    }

    /**
     * Forms started by agents over the last $days days: how many, how many the site owner activated,
     * how many of those got a real message, and which agent said it sent them.
     */
    public function agentStarts(int $days): array
    {
        $started = FormClaim::where('created_at', '>=', now()->subDays($days));
        $claimed = (clone $started)->whereNotNull('claimed_at')->pluck('public_id');

        return [
            'started' => (clone $started)->count(),
            'activated' => $claimed->count(),
            'with_message' => Form::whereIn('public_id', $claimed)->whereHas('submissions', fn ($q) => $q->where('spam', false))->count(),
            'clients' => (clone $started)->selectRaw("coalesce(client, '(not given)') as name, count(*) as n")->groupBy('name')->orderByDesc('n')->pluck('n', 'name')->all(),
        ];
    }

    /** The latest emails that did not arrive, on any account, with no address: the form and the reason say enough. */
    public function deliveryFailures(int $limit = 10): Collection
    {
        return DeliveryFailure::with('form.user')->latest('id')->limit($limit)->get();
    }

    public function abuseReports(int $limit = 10): Collection
    {
        return AbuseReport::latest('id')->limit($limit)->get();
    }

    /** What the account is right now, in two words. A self-hosted install has no trial and no bill. */
    public function status(User $user): string
    {
        return match (true) {
            $user->isOwner() => 'Owner',
            Mode::selfHosted() => $user->hasVerifiedEmail() ? 'Active' : 'Email not confirmed',
            $this->isPaying($user) => 'Paying, '.$user->formUnits().' form units',
            ! $user->hasVerifiedEmail() => 'Email not confirmed',
            $user->onGenericTrial() => 'Trial, '.max(1, (int) ceil(now()->diffInDays($user->trial_ends_at))).' days left',
            default => 'Trial ended, not paying',
        };
    }

    private function isPaying(User $user): bool
    {
        return ! $user->isOwner() && (bool) $user->subscription('default')?->valid();
    }

    /** List price per month in euros: a yearly plan counts as a twelfth of its year. */
    private function monthlyEuros(User $user): float
    {
        if (! $this->isPaying($user)) {
            return 0;
        }

        $subscription = $user->subscription('default');
        $perForm = config('billing.per_form');

        // List price for the units the subscription carries; extra submissions billed after each month are not in it.
        return match ($subscription->stripe_price) {
            $perForm['price_monthly'] => Bill::monthly((int) $subscription->quantity),
            $perForm['price_yearly'] => Bill::yearly((int) $subscription->quantity) / 12,
            default => 0,
        };
    }

    private function ownerIds(): array
    {
        $emails = config('billing.owner_emails');

        // Same test as User::isOwner(), so an owner address that is not verified counts as an outside account in both places.
        return $emails ? User::whereIn(\Illuminate\Support\Facades\DB::raw('lower(email)'), $emails)->get()->filter->isOwner()->pluck('id')->all() : [];
    }
}
