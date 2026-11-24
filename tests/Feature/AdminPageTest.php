<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormClaim;
use App\Models\User;
use App\Support\AdminStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AdminPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.owner_emails' => ['boss@formhaven.test'],
            'billing.per_form.price_monthly' => 'price_form_m',
            'billing.per_form.price_yearly' => 'price_form_y',
        ]);
    }

    private function owner(): User
    {
        return User::factory()->create(['email' => 'boss@formhaven.test']);
    }

    private function subscribe(User $user, string $price, string $status = 'active', int $quantity = 5): void
    {
        // Cashier stores a cancelled subscription with the day it ended, as Stripe's webhook does.
        $user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_'.Str::random(8), 'stripe_status' => $status, 'stripe_price' => $price, 'quantity' => $quantity,
            'ends_at' => $status === 'canceled' ? now()->subDay() : null]);
    }

    public function test_only_an_owner_sees_the_page_and_everyone_else_gets_a_404(): void
    {
        $this->get('/admin')->assertRedirect('/login');

        $this->actingAs(User::factory()->create())->get('/admin')->assertNotFound();
        // An owner address that was never verified is not an owner (User::isOwner).
        $unverified = User::factory()->unverified()->create(['email' => 'boss@formhaven.test']);
        $this->assertFalse($unverified->isOwner());
        $unverified->delete();

        $this->actingAs($this->owner())->get('/admin')->assertOk()->assertSee('Every account on FormHaven');
    }

    public function test_the_admin_link_shows_for_the_owner_only(): void
    {
        $this->actingAs(User::factory()->create())->get('/forms')->assertDontSee('>Admin</a>', false);
        $this->actingAs($this->owner())->get('/forms')->assertSee('>Admin</a>', false);
    }

    public function test_statuses_revenue_and_the_funnel_count_outside_accounts_only(): void
    {
        $owner = $this->owner();
        Form::factory()->for($owner)->create()->submissions()->create(['data' => ['message' => 'Owner site lead']]);

        $trial = User::factory()->create();
        $ended = User::factory()->create(['trial_ends_at' => now()->subDay()]);
        $unconfirmed = User::factory()->unverified()->create();
        $monthly = User::factory()->create(['trial_ends_at' => now()->subDays(30)]);
        $this->subscribe($monthly, 'price_form_m', 'active', 12);
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\SyncFormQuantity::class]);
        Form::factory()->count(11)->for($monthly)->create();
        $yearly = User::factory()->create(['trial_ends_at' => now()->subDays(30)]);
        $this->subscribe($yearly, 'price_form_y');
        $cancelled = User::factory()->create(['trial_ends_at' => now()->subDays(30)]);
        $this->subscribe($cancelled, 'price_form_m', 'canceled');

        $form = Form::factory()->for($monthly)->create();
        $form->submissions()->create(['data' => ['message' => 'Real']]);
        $form->submissions()->create(['data' => ['message' => 'Junk'], 'spam' => true]);
        Form::factory()->for($trial)->create();

        $stats = app(AdminStats::class);
        $accounts = $stats->accounts();
        $status = $accounts->mapWithKeys(fn ($a) => [$a->user->id => $a->status]);

        $this->assertSame('Owner', $status[$owner->id]);
        $this->assertStringStartsWith('Trial, ', $status[$trial->id]);
        $this->assertSame('Trial ended, not paying', $status[$ended->id]);
        $this->assertSame('Email not confirmed', $status[$unconfirmed->id]);
        $this->assertSame('Paying, 12 form units', $status[$monthly->id]);
        $this->assertSame('Paying, 5 form units', $status[$yearly->id]);
        $this->assertSame('Trial ended, not paying', $status[$cancelled->id]);

        $totals = $stats->totals($accounts);
        $this->assertSame(6, $totals['accounts'], 'The owner account is not a customer');
        $this->assertSame(2, $totals['paying']);
        // 12 monthly units (10 full, 2 at the lower price) plus 5 yearly units / 12, priced from config, not typed here.
        $expected = (int) round(\App\Support\Bill::monthly(12) + \App\Support\Bill::yearly(5) / 12);
        $this->assertSame(15, $expected, '€11 a month plus €50 a year / 12');
        $this->assertSame($expected, $totals['mrr']);

        $this->assertSame([
            'Signed up' => 6, 'Confirmed their email' => 5, 'Made a form' => 2, 'Got a real message' => 1, 'Paying' => 2,
        ], $stats->funnel($accounts));

        $this->assertSame(['real' => 1, 'spam' => 1], $stats->messages(7), "The owner's own site lead is left out");
    }

    public function test_the_page_never_shows_what_a_visitor_typed(): void
    {
        $customer = User::factory()->create(['email' => 'agency@client.test']);
        Form::factory()->for($customer)->create(['name' => 'Bakery contact'])->submissions()->create(['data' => ['message' => 'My private medical question']]);

        $this->actingAs($this->owner());

        Volt::test('admin')
            ->assertSee('agency@client.test')
            ->assertDontSee('My private medical question');
    }

    public function test_agent_starts_on_the_page_match_the_report_command(): void
    {
        FormClaim::create(['public_id' => 'f_a1', 'name' => 'A', 'client' => 'claude-code', 'claim_token_hash' => 'x1', 'setup_key_hash' => 'y1']);
        FormClaim::create(['public_id' => 'f_a2', 'name' => 'B', 'client' => null, 'claim_token_hash' => 'x2', 'setup_key_hash' => 'y2'])
            ->forceFill(['claimed_at' => now()])->save();

        $s = app(AdminStats::class)->agentStarts(7);
        $this->assertSame(2, $s['started']);
        $this->assertSame(1, $s['activated']);
        $this->assertEqualsCanonicalizing(['claude-code' => 1, '(not given)' => 1], $s['clients']);

        $this->artisan('agents:report')->expectsOutputToContain('Started:   2')->expectsOutputToContain('Activated: 1 (50%)')->assertSuccessful();
    }

    public function test_a_self_hosted_install_shows_no_billing_figures(): void
    {
        $customer = User::factory()->create(['trial_ends_at' => now()->subDays(30)]);
        $this->subscribe($customer, 'price_form_m');
        $unconfirmed = User::factory()->unverified()->create();
        $owner = $this->owner();

        // The admin route is registered in both modes; the page reads the mode when it renders.
        config(['formhaven.mode' => 'self_hosted']);

        $stats = app(AdminStats::class);
        $accounts = $stats->accounts();
        $status = $accounts->mapWithKeys(fn ($a) => [$a->user->id => $a->status]);
        $this->assertSame('Active', $status[$customer->id], 'no trial and no bill on a self-hosted install');
        $this->assertSame('Email not confirmed', $status[$unconfirmed->id]);
        $this->assertSame('Owner', $status[$owner->id]);
        $this->assertArrayNotHasKey('Paying', $stats->funnel($accounts));

        $this->actingAs($owner);
        $page = Volt::test('admin')
            ->assertSee('Every account on this install')
            ->assertSee('From signup to the first message')
            ->assertSee('Real messages');
        foreach (['Paying', 'Monthly revenue', '€', 'Trial', 'trial', 'FormHaven'] as $billing) {
            $page->assertDontSee($billing);
        }

        // The control: the hosted page keeps every figure.
        config(['formhaven.mode' => 'hosted']);
        Volt::test('admin')->assertSee('Monthly revenue')->assertSee('From signup to paying')->assertSee('Paying, 5 form units');
    }
}
