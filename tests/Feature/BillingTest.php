<?php

namespace Tests\Feature;

use App\Jobs\SyncFormQuantity;
use App\Mail\SubmissionReceived;
use App\Mail\UsageNotice;
use App\Models\Form;
use App\Models\MonthlyUsage;
use App\Models\User;
use App\Support\Bill;
use App\Support\ExtraUsageBiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.per_form.price_monthly' => 'price_form_m',
            'billing.per_form.price_yearly' => 'price_form_y',
            'billing.per_form.price_extra' => 'price_extra',
        ]);
        // The quantity sync calls Stripe; these tests check when it is queued, not Stripe.
        Bus::fake([SyncFormQuantity::class]);
    }

    private function subscribe(User $user, string $price = 'price_form_m'): void
    {
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_'.$price.$user->id,
            'stripe_status' => 'active',
            'stripe_price' => $price,
            'quantity' => $user->formUnits(),
        ]);
    }

    /** What SyncFormQuantity does once its wait is over: Stripe confirms the quantity, and the month's peak may rise. */
    private function billed(User $user): void
    {
        $user->subscription('default')->forceFill(['quantity' => $user->formUnits()])->save();
        MonthlyUsage::add($user->id, now(), 0, $user->formUnits());
        $user->unsetRelation('subscriptions');
    }

    private function expiredUser(): User
    {
        return User::factory()->create(['trial_ends_at' => now()->subDay()]);
    }

    public function test_a_new_account_gets_a_14_day_trial_with_a_form_cap(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->trial_ends_at->between(now()->addDays(13), now()->addDays(14)));
        $this->assertSame('trial', $user->planKey());
        $this->assertSame(25, $user->plan()['forms']);
        // Fewer than five forms still include the minimum's 5,000.
        $this->assertSame(5000, $user->plan()['submissions']);
    }

    public function test_only_the_per_form_price_counts_as_paying_monthly_or_yearly(): void
    {
        $monthly = $this->expiredUser();
        $this->subscribe($monthly, 'price_form_m');
        $yearly = $this->expiredUser();
        $this->subscribe($yearly, 'price_form_y');
        $other = $this->expiredUser();
        $this->subscribe($other, 'price_from_somewhere_else');

        $this->assertSame('paid', $monthly->planKey());
        $this->assertSame('paid', $yearly->planKey());
        $this->assertNull($other->planKey());
    }

    public function test_the_included_submissions_grow_with_forms_past_the_minimum(): void
    {
        $user = $this->expiredUser();
        $this->subscribe($user);

        Form::factory()->count(2)->for($user)->create();
        $this->assertSame(5, $user->formUnits());
        $this->assertSame(5000, $user->plan()['submissions']);

        Form::factory()->count(10)->for($user)->create();
        $this->assertSame(12, $user->formUnits());
        // Not billed yet (the quantity sync waits an hour), so not in the pool yet.
        $this->assertSame(5000, $user->plan()['submissions']);

        $this->billed($user);
        $this->assertSame(12000, $user->fresh()->plan()['submissions']);
    }

    public function test_forms_made_and_deleted_before_they_are_billed_add_nothing_to_the_pool(): void
    {
        Mail::fake();
        $user = $this->expiredUser();
        $this->subscribe($user);
        $keep = Form::factory()->for($user)->create();

        // 50 forms, one message while they exist, all deleted inside the sync's wait.
        $burst = Form::factory()->count(50)->for($user)->create();
        $this->postJson("/f/{$keep->public_id}", ['message' => 'Hi'])->assertOk();
        $burst->each->delete();

        $this->assertSame(5000, $user->fresh()->plan()['submissions']);
        $this->assertSame(5, $user->fresh()->poolUnits(now()));
        $this->assertSame(2, $user->fresh()->extraUnits(6001));

        // Control: the same 50 forms kept until the sync bills them do widen the pool.
        Form::factory()->count(50)->for($user)->create();
        $this->billed($user);
        $this->assertSame(51000, $user->fresh()->plan()['submissions']);
    }

    public function test_extra_units_are_started_thousands_past_the_pool(): void
    {
        $user = $this->expiredUser();
        $this->subscribe($user);

        $this->assertSame(0, $user->extraUnits(5000));
        $this->assertSame(1, $user->extraUnits(5001));
        $this->assertSame(1, $user->extraUnits(6000));
        $this->assertSame(2, $user->extraUnits(6001));

        // More forms widen the pool, so the same month costs nothing extra.
        Form::factory()->count(7)->for($user)->create();
        $this->billed($user);
        $this->assertSame(0, $user->fresh()->extraUnits(6001));
    }

    public function test_after_the_trial_with_no_subscription_forms_are_paused_and_nothing_is_stored(): void
    {
        $form = Form::factory()->for($this->expiredUser())->create();

        $this->postJson("/f/{$form->public_id}", ['message' => 'Hi'])
            ->assertStatus(402)
            ->assertJson(['ok' => false]);

        $this->post("/f/{$form->public_id}", ['message' => 'Hi'])
            ->assertStatus(402)
            ->assertSee('This form is paused');

        $this->assertSame(0, $form->submissions()->count());
    }

    public function test_an_owner_account_never_pauses_after_its_trial(): void
    {
        config(['billing.owner_emails' => ['owner@formhaven.test']]);
        $owner = User::factory()->create(['email' => 'Owner@FormHaven.test', 'trial_ends_at' => now()->subMonth()]);
        $forms = Form::factory()->for($owner)->count(30)->create();
        $stranger = User::factory()->create(['trial_ends_at' => now()->subMonth()]);

        $this->assertSame('owner', $owner->planKey());
        $this->assertNull($stranger->planKey());
        $this->postJson("/f/{$forms->last()->public_id}", ['message' => 'Hello'])->assertOk()->assertJsonPath('ok', true);
        $this->assertTrue($owner->canCreateForm());

        // An unconfirmed address with the owner's email gets nothing: anyone can type an email at sign-up.
        $owner->forceFill(['email_verified_at' => null])->save();
        $this->assertNull($owner->fresh()->planKey());
    }

    public function test_the_trial_form_cap_is_enforced_with_a_reason(): void
    {
        config(['billing.trial_forms' => 3]);
        $user = User::factory()->create();
        Form::factory()->count(3)->for($user)->create();
        $this->actingAs($user);

        Volt::test('forms.index')
            ->set('name', 'Fourth form')
            ->call('createForm')
            ->assertHasErrors('name')
            ->assertSee('Your trial includes 3 forms. Subscribe on the Billing page to add more: the first 10 forms are €1 each a month, every form from the 11th €0.50.');

        $this->assertSame(3, $user->forms()->count());
    }

    public function test_a_paying_account_has_no_form_cap_and_every_add_or_delete_syncs_the_quantity(): void
    {
        $user = $this->expiredUser();
        $this->subscribe($user);
        Form::factory()->count(30)->for($user)->create();

        $this->assertTrue($user->canCreateForm());
        // Unique per account until it runs: thirty forms in a row queue one sync, not thirty.
        Bus::assertDispatchedTimes(SyncFormQuantity::class, 1);
        Bus::assertDispatched(SyncFormQuantity::class, fn ($job) => $job->userId === $user->id);

        // Once that sync has run (its lock released), a delete queues the next one.
        \Illuminate\Support\Facades\Cache::lock(\Illuminate\Bus\UniqueLock::getKey(new SyncFormQuantity($user->id)))->forceRelease();
        $user->forms()->first()->delete();
        Bus::assertDispatchedTimes(SyncFormQuantity::class, 2);
    }

    public function test_the_quantity_sync_leaves_stripe_alone_when_nothing_changed_or_nobody_pays(): void
    {
        $trial = User::factory()->create();
        Form::factory()->for($trial)->create();
        $paying = $this->expiredUser();
        $this->subscribe($paying);

        // Neither reaches Stripe: no subscription at all, and a quantity that already matches (5).
        // A Stripe call here would throw, as the tests have no Stripe key.
        (new SyncFormQuantity($trial->id))->handle();
        (new SyncFormQuantity($paying->id))->handle();

        $this->assertSame(5, (int) $paying->subscription('default')->quantity);
    }

    public function test_on_a_trial_past_the_limit_plus_grace_messages_are_kept_but_not_emailed(): void
    {
        Mail::fake();
        config(['billing.per_form.submissions' => 2]); // 5 units, so 10 included
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        // 10 plus 10% grace = 11 emailed, the 12th and 13th only saved.
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach (range(1, 13) as $i) {
            $this->postJson("/f/{$form->public_id}", ['message' => "m{$i}"])->assertOk();
        }

        $this->assertSame(13, $form->submissions()->count());
        Mail::assertQueued(SubmissionReceived::class, 11);

        $this->actingAs($user)->get(route('forms.index'))
            ->assertSee('New ones are still saved here, but no longer emailed');
    }

    public function test_a_paying_account_is_never_cut_off_and_is_told_what_extra_costs(): void
    {
        Mail::fake();
        config(['billing.per_form.submissions' => 2]); // 5 units, so 10 included
        $user = $this->expiredUser();
        $this->subscribe($user);
        $form = Form::factory()->for($user)->create();

        $this->withoutMiddleware(ThrottleRequests::class);
        foreach (range(1, 15) as $i) {
            $this->postJson("/f/{$form->public_id}", ['message' => "m{$i}"])->assertOk();
        }

        Mail::assertQueued(SubmissionReceived::class, 15);
        $this->assertFalse($user->emailsPaused());
        $this->assertSame(3, $user->extraUnits($user->submissionsThisMonth()));

        $this->actingAs($user)->get(route('forms.index'))
            ->assertSee('Every message is still emailed. Each extra 1,000 costs €0.50, added to your next invoice once the month ends.')
            ->assertDontSee('no longer emailed');
    }

    public function test_on_a_trial_at_the_limit_but_inside_the_grace_the_owner_is_told_emails_continue(): void
    {
        Mail::fake();
        config(['billing.per_form.submissions' => 2]);
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        foreach (range(1, 10) as $i) {
            $this->postJson("/f/{$form->public_id}", ['message' => "m{$i}"])->assertOk();
        }

        $this->actingAs($user)->get(route('forms.index'))
            ->assertSee('We keep emailing new ones until 11');
    }

    public function test_usage_emails_go_out_once_per_level_per_month(): void
    {
        Mail::fake();
        config(['billing.per_form.submissions' => 2]);
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $this->withoutMiddleware(ThrottleRequests::class);
        $levelsAfter = [];
        foreach (range(1, 13) as $i) {
            $this->postJson("/f/{$form->public_id}", ['message' => "m{$i}"])->assertOk();
            $levelsAfter[$i] = Mail::queued(UsageNotice::class)->map->level->values()->all();
        }

        $this->assertSame([], $levelsAfter[7]);
        $this->assertSame(['80'], $levelsAfter[8]);
        $this->assertSame(['80', '100'], $levelsAfter[10]);
        $this->assertSame(['80', '100', 'stopped'], $levelsAfter[11]);
        $this->assertSame(['80', '100', 'stopped'], $levelsAfter[13]);

        // Spam never triggers a notice.
        $this->postJson("/f/{$form->public_id}", ['message' => 'x', '_gotcha' => 'bot'])->assertOk();
        Mail::assertQueued(UsageNotice::class, 3);

        // A new month starts over.
        $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->addDay());
        foreach (range(1, 8) as $i) {
            $this->postJson("/f/{$form->public_id}", ['message' => "n{$i}"])->assertOk();
        }
        Mail::assertQueued(UsageNotice::class, 4);
    }

    public function test_a_paying_account_gets_no_stopped_notice(): void
    {
        Mail::fake();
        config(['billing.per_form.submissions' => 2]);
        $user = $this->expiredUser();
        $this->subscribe($user);
        $form = Form::factory()->for($user)->create();

        $this->withoutMiddleware(ThrottleRequests::class);
        foreach (range(1, 13) as $i) {
            $this->postJson("/f/{$form->public_id}", ['message' => "m{$i}"])->assertOk();
        }

        $this->assertSame(['80', '100'], Mail::queued(UsageNotice::class)->map->level->values()->all());
    }

    public function test_usage_email_reads_right_for_trial_and_paid(): void
    {
        $trial = User::factory()->create();

        $this->assertStringContainsString('every message is still emailed', (new UsageNotice($trial, '80', 4000))->render());
        $this->assertStringContainsString('We keep emailing the next 500', (new UsageNotice($trial, '100', 5000))->render());
        $this->assertStringContainsString('we no longer email them to you', (new UsageNotice($trial, 'stopped', 5500))->render());
        $this->assertSame('Form emails paused: new messages are only in your dashboard', (new UsageNotice($trial, 'stopped', 5500))->envelope()->subject);

        $paid = $this->expiredUser();
        $this->subscribe($paid);
        $full = (new UsageNotice($paid, '100', 5000))->render();
        $this->assertStringContainsString('Every new message is still emailed to you. Each extra 1,000 costs €0.50, added to your next invoice once the month ends.', $full);
        $this->assertStringNotContainsString('until you subscribe', $full);
        $this->assertSame('You used this month\'s included FormHaven submissions', (new UsageNotice($paid, '100', 5000))->envelope()->subject);
    }

    public function test_the_month_count_survives_short_retention_and_ignores_dashboard_tests_and_spam(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(3));
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create(['retention_days' => 1]);

        foreach (range(1, 3) as $i) {
            $this->postJson("/f/{$form->public_id}", ['message' => "m{$i}"])->assertOk();
        }
        $this->postJson("/f/{$form->public_id}", ['message' => 'x', '_gotcha' => 'bot'])->assertOk();

        // The rows are pruned within the month; the count the bill reads is not.
        $this->travel(2)->days();
        $this->artisan('submissions:prune');
        $this->assertSame(0, $form->submissions()->where('spam', false)->count());
        $this->assertSame(3, $user->submissionsThisMonth());
    }

    public function test_block_and_not_spam_move_a_message_out_of_and_back_into_the_count(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $this->postJson("/f/{$form->public_id}", ['message' => 'Buy our SEO', 'email' => 'pitch@seo.test'])->assertOk();
        $submission = $form->submissions()->first();

        app(\App\Actions\BlockSender::class)->block($submission, '@seo.test');
        $this->assertSame(0, $user->submissionsThisMonth());

        app(\App\Actions\BlockSender::class)->unblock($submission->fresh());
        $this->assertSame(1, $user->submissionsThisMonth());
    }

    public function test_last_months_submissions_do_not_count(): void
    {
        $user = $this->expiredUser();
        $this->subscribe($user);
        MonthlyUsage::add($user->id, now()->subMonthNoOverflow(), 9000);

        $this->assertSame(0, $user->submissionsThisMonth());
        $this->assertFalse($user->overSubmissionLimit());
    }

    public function test_the_first_of_the_month_charges_extra_thousands_once_and_only_to_paying_accounts(): void
    {
        $log = new \ArrayObject;
        $this->app->instance(ExtraUsageBiller::class, new class($log) extends ExtraUsageBiller
        {
            public function __construct(public \ArrayObject $log) {}

            public function charge(User $user, int $units, string $month): void
            {
                $this->log->append([$user->email, $units]);
            }
        });
        $charged = fn () => $log->getArrayCopy();

        $last = now()->subMonthNoOverflow();
        $busy = $this->expiredUser();
        $this->subscribe($busy);
        MonthlyUsage::add($busy->id, $last, 7200); // 5,000 included, 2,200 past it: 3 units
        $quiet = $this->expiredUser();
        $this->subscribe($quiet);
        MonthlyUsage::add($quiet->id, $last, 4000);
        $trial = User::factory()->create();
        MonthlyUsage::add($trial->id, $last, 9000);
        MonthlyUsage::add($busy->id, now(), 9000); // this month: not billed yet

        $this->artisan('billing:charge-extra --dry-run')->assertSuccessful();
        $this->assertSame([], $charged());

        $this->artisan('billing:charge-extra')->assertSuccessful();
        $this->assertSame([[$busy->email, 3]], $charged());
        $this->assertSame(3, MonthlyUsage::where('user_id', $busy->id)->where('month', MonthlyUsage::monthOf($last))->value('charged_units'));
        $this->assertSame(0, MonthlyUsage::where('user_id', $quiet->id)->where('month', MonthlyUsage::monthOf($last))->value('charged_units'));

        // The daily run never bills the same month twice.
        $this->artisan('billing:charge-extra')->assertSuccessful();
        $this->assertCount(1, $charged());
    }

    public function test_the_charge_refuses_to_run_without_the_extra_price(): void
    {
        config(['billing.per_form.price_extra' => null]);

        $this->artisan('billing:charge-extra')->assertFailed();
    }

    public function test_billing_page_shows_the_per_form_price_and_works_after_the_trial(): void
    {
        $user = User::factory()->create();
        Form::factory()->count(2)->for($user)->create();

        $this->actingAs($user)->get(route('billing'))
            ->assertOk()
            ->assertSeeText('Free trial')
            ->assertSeeText('2 of 25')
            ->assertSeeText('€5 a month for your 2 forms')
            ->assertSeeText('€50 a year for your 2 forms');

        $this->actingAs($this->expiredUser())->get(route('billing'))
            ->assertOk()
            ->assertSeeText('Your trial has ended');

        $paying = $this->expiredUser();
        Form::factory()->count(7)->for($paying)->create();
        $this->subscribe($paying);
        $this->actingAs($paying)->get(route('billing'))
            ->assertOk()
            ->assertSeeText('€7 a month')
            ->assertSeeText('7 form units')
            ->assertDontSeeText('Subscribe for');
    }

    public function test_pricing_page_shows_the_per_form_price_and_example_bills_from_config(): void
    {
        $p = config('billing.per_form');
        $response = $this->get('/pricing')->assertOk();

        $response->assertSeeText('€'.$p['monthly_eur'].' per form a month')
            ->assertSeeText('€'.$p['yearly_eur'].' per form a year')
            ->assertSeeText('For at least '.$p['min_forms'].' forms')
            ->assertSeeText('From the 11th form, €0.50.')
            // 25 forms: 10 at the full price, 15 at the lower one. Every form adds its submissions.
            ->assertSeeText(Bill::format(Bill::monthly(25)).' a month')
            ->assertSeeText('€17.50 a month')
            ->assertSeeText('€175 a year')
            ->assertSeeText('€55 a month')
            ->assertSeeText(number_format(25 * $p['submissions']));
    }

    public function test_the_bill_is_graduated_past_the_tenth_form(): void
    {
        // The approved figures of 2026-11-21, typed out so a config or helper slip shows here.
        foreach ([5 => [5, 50], 10 => [10, 100], 11 => [10.5, 105], 25 => [17.5, 175], 30 => [20, 200], 50 => [30, 300], 100 => [55, 550]] as $units => [$month, $year]) {
            $this->assertSame((float) $month, Bill::monthly($units), "{$units} forms a month");
            $this->assertSame((float) $year, Bill::yearly($units), "{$units} forms a year");
        }

        // Below the minimum the bill is the minimum's.
        $this->assertSame(5.0, Bill::monthly(3));
        $this->assertSame(50.0, Bill::yearly(3));
        $this->assertSame(5.0, Bill::monthly(0));

        $this->assertSame('€20', Bill::format(20));
        $this->assertSame('€17.50', Bill::format(17.5));
        $this->assertSame('€0.50', Bill::format(0.5));
        $this->assertSame('€1', Bill::format(1.0));
        $this->assertSame('10.50', Bill::format(10.5, ''));
        $this->assertSame('11th', Bill::firstLowerForm());
    }

    public function test_empty_forms_never_buy_submissions_cheaper_than_extra_thousands(): void
    {
        // Every form adds the same submissions to the pool, so if an extra 1,000 cost more than a form, an
        // account could create empty forms to pay less. The normal bill (the
        // forms it has, the rest as extra thousands) may never beat the bill padded with empty forms.
        $p = config('billing.per_form');

        foreach ([1, 5, 10, 11, 25, 60] as $forms) {
            foreach ([0, 5000, 5001, 25000, 100000, 250000] as $submissions) {
                $units = max($p['min_forms'], $forms);
                $needed = (int) ceil($submissions / $p['submissions']);
                $normal = Bill::monthly($units) + max(0, $needed - $units) * $p['extra_eur'];
                $padded = Bill::monthly(max($units, $needed));

                $this->assertLessThanOrEqual(
                    (int) round($padded * 100),
                    (int) round($normal * 100),
                    "{$forms} forms, {$submissions} submissions: ".Bill::format($normal).' normally, '.Bill::format($padded).' padded with empty forms',
                );
            }
        }
    }

    public function test_the_billing_page_shows_the_graduated_total_for_25_forms(): void
    {
        $trial = User::factory()->create();
        Form::factory()->count(25)->for($trial)->create();

        $this->actingAs($trial)->get(route('billing'))->assertOk()
            ->assertSeeText('€17.50 a month for your 25 forms')
            ->assertSeeText('€175 a year for your 25 forms')
            ->assertSeeText('Subscribe for €17.50 a month')
            ->assertSeeText('Subscribe for €175 a year')
            ->assertDontSeeText('€25 a month');

        $paying = $this->expiredUser();
        Form::factory()->count(25)->for($paying)->create();
        $this->subscribe($paying);
        $this->actingAs($paying)->get(route('billing'))->assertOk()
            ->assertSeeText('€17.50 a month')
            ->assertSeeText('25 form units');

        $yearly = $this->expiredUser();
        Form::factory()->count(25)->for($yearly)->create();
        $this->subscribe($yearly, 'price_form_y');
        $this->actingAs($yearly)->get(route('billing'))->assertOk()->assertSeeText('€175 a year');

        // Control: at the minimum nothing changed.
        $small = User::factory()->create();
        Form::factory()->count(5)->for($small)->create();
        $this->actingAs($small)->get(route('billing'))->assertOk()
            ->assertSeeText('€5 a month for your 5 forms')
            ->assertSeeText('€50 a year for your 5 forms');
    }

    public function test_a_failed_payment_keeps_the_forms_on_and_tells_the_owner(): void
    {
        $user = $this->expiredUser();
        $this->subscribe($user);
        $this->actingAs($user)->get(route('billing'))->assertDontSee('Your last payment failed');

        // A failed renewal, yearly add-on or extra-usage invoice: Stripe is still retrying the card.
        $user->subscription('default')->forceFill(['stripe_status' => 'past_due'])->save();
        $user = $user->fresh();
        $this->assertTrue($user->isPaying());
        $this->assertNull($user->emailCutoff());
        $this->actingAs($user)->get(route('billing'))->assertSee('Your last payment failed');

        // Stripe gave up and cancelled: the account is off.
        $user->subscription('default')->forceFill(['stripe_status' => 'canceled', 'ends_at' => now()])->save();
        $this->assertFalse($user->fresh()->isPaying());
        $this->assertNull($user->fresh()->plan());
    }

    /** Swaps the Stripe calls for a log of [email, units]. */
    private function fakeBiller(): \ArrayObject
    {
        $log = new \ArrayObject;
        $this->app->instance(ExtraUsageBiller::class, new class($log) extends ExtraUsageBiller
        {
            public function __construct(public \ArrayObject $log) {}

            public function charge(User $user, int $units, string $month): void
            {
                $this->log->append([$user->email, $units]);
            }
        });

        return $log;
    }

    public function test_forms_deleted_after_the_submissions_arrived_do_not_shrink_that_months_pool(): void
    {
        Mail::fake();
        $log = $this->fakeBiller();
        config(['billing.per_form.submissions' => 2]);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(now()->subMonthNoOverflow()->startOfMonth()->addDays(9));

        // 10 forms, so a pool of 20; 25 submissions is 5 past it: 3 started units of 2.
        $shrinks = $this->expiredUser();
        $this->subscribe($shrinks);
        $forms = Form::factory()->count(10)->for($shrinks)->create();
        $this->billed($shrinks);
        // Control: 8 forms all month, a pool of 16; 25 submissions is 9 past it: 5 units, as before.
        $steady = $this->expiredUser();
        $this->subscribe($steady);
        $steadyForm = Form::factory()->count(8)->for($steady)->create()->first();
        $this->billed($steady);

        foreach (range(1, 25) as $i) {
            $this->postJson("/f/{$forms->first()->public_id}", ['message' => "m{$i}"])->assertOk();
            $this->postJson("/f/{$steadyForm->public_id}", ['message' => "m{$i}"])->assertOk();
        }

        // On the last day, 8 of the 10 forms go: today's form units are the minimum of 5.
        $this->travelTo(now()->endOfMonth()->subHour());
        $forms->slice(2)->each->delete();
        $this->assertSame(5, $shrinks->formUnits());
        $shrinks->subscription('default')->forceFill(['quantity' => 5])->save();

        $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->addHour());
        $this->artisan('billing:charge-extra')->assertSuccessful();

        $this->assertEqualsCanonicalizing([[$shrinks->email, 3], [$steady->email, 5]], $log->getArrayCopy());
    }

    public function test_a_counted_submission_records_the_months_peak_for_forms_made_before_it(): void
    {
        $user = $this->expiredUser();
        $this->subscribe($user);
        $form = Form::factory()->count(9)->for($user)->create()->first();
        // Creating a form records nothing: it is not billed until the quantity sync has run.
        $this->assertNull(MonthlyUsage::where('user_id', $user->id)->value('peak_units'));
        // Billed in an earlier month, so this month has no peak yet.
        $user->subscription('default')->forceFill(['quantity' => 9])->save();

        $this->postJson("/f/{$form->public_id}", ['message' => 'Hi'])->assertOk();

        $this->assertSame(9, MonthlyUsage::where('user_id', $user->id)->value('peak_units'));
    }

    public function test_a_month_with_no_recorded_peak_is_charged_against_the_minimum(): void
    {
        $log = $this->fakeBiller();
        $user = $this->expiredUser();
        $this->subscribe($user);
        Form::factory()->count(12)->for($user)->create();
        $this->billed($user); // this month's peak, not last month's
        MonthlyUsage::add($user->id, now()->subMonthNoOverflow(), 7200); // no peak: 5,000 included, 3 units

        $this->artisan('billing:charge-extra')->assertSuccessful();

        $this->assertSame([[$user->email, 3]], $log->getArrayCopy());
        // This month's pool still reads the units billed now.
        $this->assertSame(12000, $user->fresh()->plan()['submissions']);
    }

    public function test_the_quantity_sync_waits_an_hour_so_a_form_made_and_deleted_inside_it_costs_nothing(): void
    {
        $this->freezeTime();
        $user = $this->expiredUser();
        $this->subscribe($user);

        Form::factory()->for($user)->create();

        $this->assertSame(60, config('billing.per_form.sync_delay_minutes'));
        Bus::assertDispatched(SyncFormQuantity::class, fn ($job) => $job->delay instanceof \DateTimeInterface
            && now()->addMinutes(60)->equalTo($job->delay));
    }
}
