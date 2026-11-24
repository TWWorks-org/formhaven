<?php

namespace Tests\Feature;

use App\Actions\FormSettings;
use App\Actions\RetryDeliveries;
use App\Jobs\SendMonitorHeartbeat;
use App\Mail\DeliveryRefused;
use App\Mail\SubmissionReceived;
use App\Mcp\Servers\FormHavenServer;
use App\Mcp\Tools\ListFormsTool;
use App\Mcp\Tools\UpdateFormTool;
use App\Models\DeliveryFailure;
use App\Models\Form;
use App\Models\FormRecipient;
use App\Models\Submission;
use App\Models\User;
use App\Support\DidMyFormSend;
use App\Support\PrivacyText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The opt-in DidMyFormSend report (App\Support\DidMyFormSend, App\Jobs\SendMonitorHeartbeat).
 * The axes: whether the form holds a key (none means no request, ever), the outcome of each of a
 * submission's emails and the order they arrive in, the mail service's name, and what the
 * receiving side answers. The regexes below are copied from the receiving side (DidMyFormSend's
 * heartbeat endpoint and its recipient validation) as the contract.
 */
class HeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'fmh_TestKeyAbcdefghijklmnopqrstuvwxyz0123456';

    private const OTHER_KEY = 'fmh_OtherKey9876543210ZYXWVUTSRQPONMLKJIHGFE';

    private const CONTRACT = [
        'result' => '/^(sent|failed|skipped)$/',
        'provider' => '/^[a-z0-9_]{1,32}$/',
        'provider_status' => '/^[A-Za-z0-9_.:-]{1,64}$/',
        'event_id' => '/^[A-Za-z0-9_-]{1,64}$/',
        'masked' => '/^[a-z0-9._%+-]\*\*\*@[a-z0-9-]+(\.[a-z0-9-]+)+$/',
        'sha256' => '/^[0-9a-f]{64}$/',
    ];

    private const ALLOWED = ['result', 'provider', 'provider_status', 'http_status', 'event_id', 'occurred_at', 'recipients'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['services.didmyformsend.url' => 'https://didmyformsend.com']);
        // One stub that reads $answer: a second Http::fake for the same host would not replace the first.
        $this->answer = fn () => Http::response(['ok' => true], 202);
        Http::fake(['didmyformsend.com/*' => fn ($request) => ($this->answer)($request)]);
    }

    private \Closure $answer;

    private function fakeMonitor(int $status = 202): void
    {
        $this->answer = fn () => Http::response(['ok' => true], $status);
    }

    /** @return list<array{body: array, raw: string, request: \Illuminate\Http\Client\Request}> */
    private function beats(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'didmyformsend.com'))
            ->map(fn ($pair) => ['body' => json_decode($pair[0]->body(), true), 'raw' => $pair[0]->body(), 'request' => $pair[0]])
            ->values()->all();
    }

    private function form(array $attributes = [], ?string $key = self::KEY, string $owner = 'Owner@Agency.example'): Form
    {
        $user = User::factory()->create(['email' => $owner]);
        $form = Form::factory()->for($user)->create($attributes);
        if ($key !== null) {
            app(FormSettings::class)->update($form, ['monitor_key' => $key]);
        }

        return $form->fresh();
    }

    private function recipient(Form $form, string $email): FormRecipient
    {
        $recipient = $form->recipients()->create(['email' => $email]);
        $recipient->forceFill(['confirmed_at' => now()])->save();

        return $recipient;
    }

    private function submission(Form $form, int $pending): Submission
    {
        $submission = $form->submissions()->create(['data' => ['name' => 'Anna', 'message' => 'Hoi']]);
        $submission->forceFill(['emails_pending' => $pending])->save();

        return $submission->fresh();
    }

    private function mail(Submission $submission, ?FormRecipient $recipient = null, ?int $retryOf = null): SubmissionReceived
    {
        return (new SubmissionReceived($submission->fresh(), $recipient, $retryOf))->to($recipient?->email ?? $submission->form->user->email);
    }

    private function sendNow(SubmissionReceived $mail): void
    {
        $mail->send(app(\Illuminate\Contracts\Mail\Factory::class));
    }

    private function submit(Form $form, array $fields = ['name' => 'Anna', 'message' => 'Hoi'])
    {
        return $this->post("/f/{$form->public_id}", $fields, ['Accept' => 'application/json']);
    }

    private function assertContract(array $body, string $what): void
    {
        $this->assertSame([], array_diff(array_keys($body), self::ALLOWED), "{$what}: a field the receiving side refuses");
        foreach (['result', 'provider', 'event_id'] as $field) {
            $this->assertMatchesRegularExpression(self::CONTRACT[$field], (string) $body[$field], "{$what}: {$field}");
        }
        if (array_key_exists('provider_status', $body)) {
            $this->assertMatchesRegularExpression(self::CONTRACT['provider_status'], $body['provider_status'], "{$what}: provider_status");
        }
        $this->assertIsInt($body['occurred_at'], "{$what}: occurred_at must be unix seconds");
        $this->assertLessThanOrEqual(5, count($body['recipients'] ?? []));
        foreach ($body['recipients'] ?? [] as $r) {
            $this->assertSame(['masked', 'sha256'], array_keys($r));
            $this->assertMatchesRegularExpression(self::CONTRACT['masked'], $r['masked'], "{$what}: mask");
            $this->assertMatchesRegularExpression(self::CONTRACT['sha256'], $r['sha256'], "{$what}: hash");
        }
        // The receiving side refuses any @ outside a well-formed mask.
        $withoutMasks = $body;
        foreach ($withoutMasks['recipients'] ?? [] as $i => $r) {
            unset($withoutMasks['recipients'][$i]['masked']);
        }
        $this->assertStringNotContainsString('@', json_encode($withoutMasks), "{$what}: an @ outside a mask");
        $this->assertLessThanOrEqual(2048, strlen(json_encode($body)));
    }

    // 1. No key: nothing, anywhere.

    public function test_without_a_key_no_path_queues_a_report_or_makes_a_request(): void
    {
        Bus::fake([SendMonitorHeartbeat::class]);
        Http::fake();

        // Sent: the real path, the array mailer accepts it.
        $form = $this->form(key: null);
        $this->recipient($form, 'client@client.example');
        $this->submit($form)->assertOk();
        $this->assertSame(0, (int) $form->submissions()->latest('id')->first()->emails_pending, 'the emails went out');

        // Failed after the last try.
        $submission = $this->submission($form, 1);
        $this->mail($submission)->failed(new DeliveryRefused('bounced', DeliveryRefused::BOUNCED));
        $this->assertSame(1, DeliveryFailure::where('submission_id', $submission->id)->count());

        // Spam.
        $this->submit($form, ['name' => 'Bot', '_gotcha' => 'filled'])->assertOk();

        // Held by the daily budget.
        config(['mail.budget.per_form_per_day' => 0]);
        $this->submit($form)->assertOk();
        $this->assertSame(2, DeliveryFailure::whereIn('kind', [DeliveryFailure::HELD_FORM])->count());
        config(['mail.budget.per_form_per_day' => 1000]);

        // Send again, which delivers the held and the failed emails.
        $result = app(RetryDeliveries::class)->handle($form);
        $this->assertSame(3, $result['queued']);

        Bus::assertNotDispatched(SendMonitorHeartbeat::class);
        Http::assertNothingSent();
    }

    // 2. With a key: the body, the header, the recipients.

    public function test_with_a_key_one_report_carries_exactly_the_contract_and_nothing_a_visitor_wrote(): void
    {
        $this->fakeMonitor();
        $form = $this->form(['name' => 'Acme Quote Form', 'site_url' => 'https://acme-client.example']);
        $this->recipient($form, 'Kim@Client.example');
        $this->recipient($form, 'lee@other-client.example');

        $this->submit($form, [
            'name' => 'Visitor Person', 'email' => 'visitor@visitor-site.example',
            'message' => 'SECRET-ANSWER-1234', '_subject' => 'Quote please',
        ])->assertOk();

        $beats = $this->beats();
        $this->assertCount(1, $beats, 'one report per submission, not one per email');
        [$beat] = $beats;
        $body = $beat['body'];

        $this->assertSame(['result', 'provider', 'occurred_at', 'event_id', 'recipients'], array_keys($body));
        $this->assertSame('sent', $body['result']);
        $this->assertSame('array', $body['provider']);
        $this->assertEqualsWithDelta(time(), $body['occurred_at'], 5);
        $this->assertSame(['Bearer '.self::KEY], $beat['request']->header('Authorization'));
        $this->assertSame('https://didmyformsend.com/api/v1/heartbeat', $beat['request']->url());
        $this->assertSame([
            ['masked' => 'o***@agency.example', 'sha256' => hash('sha256', 'owner@agency.example')],
            ['masked' => 'k***@client.example', 'sha256' => hash('sha256', 'kim@client.example')],
            ['masked' => 'l***@other-client.example', 'sha256' => hash('sha256', 'lee@other-client.example')],
        ], $body['recipients'], 'every target, owner first, then by when they were added');
        $this->assertContract($body, 'sent');

        foreach (['Visitor Person', 'visitor@visitor-site.example', 'visitor-site', 'SECRET-ANSWER', 'Quote please', 'Acme', 'acme-client', $form->public_id, 'Owner@', 'owner@agency', 'kim@client'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $beat['raw'], "the request carries {$leak}");
        }

    }

    // 3. The receiving rules, for several mailer names and every failure kind.

    public function test_every_body_matches_the_receiving_rules_for_each_mailer_and_each_failure_kind(): void
    {
        $this->fakeMonitor();
        $form = $this->form();
        $this->recipient($form, 'a.b+c@client.example');
        $mailers = ['smtp' => 'smtp', 'cloudflare' => 'cloudflare', 'Cloud-Flare.EU Relay!' => 'cloud_flare_eu_relay', '' => 'array'];
        $kinds = ['bounced', 'suppressed', 'error'];
        $this->assertSame(array_values(array_diff(DeliveryFailure::KINDS, [DeliveryFailure::HELD_FORM, DeliveryFailure::HELD_ALL])), $kinds, 'a new failure kind needs a case here');

        foreach ($mailers as $mailer => $cleaned) {
            $sent = $this->submission($form, 0);
            DidMyFormSend::afterMail($sent, true, null, $mailer ?: null);
            foreach ($kinds as $kind) {
                $failed = $this->submission($form, 1);
                $failure = DeliveryFailure::record($form, $failed, null, 'owner@agency.example', $kind === 'error' ? new \RuntimeException('down') : new DeliveryRefused('x', $kind));
                DidMyFormSend::afterMail($failed, false, null, $mailer ?: null);
                $this->assertSame($kind, $failure->kind);
            }

            $beats = array_slice($this->beats(), -4);
            $this->assertSame(['sent', 'failed', 'failed', 'failed'], array_column(array_column($beats, 'body'), 'result'));
            $this->assertSame([null, ...$kinds], array_map(fn ($b) => $b['body']['provider_status'] ?? null, $beats));
            foreach ($beats as $beat) {
                $this->assertSame($cleaned, $beat['body']['provider']);
                $this->assertContract($beat['body'], "mailer {$mailer}");
            }
        }

        $this->assertSame('mail', DidMyFormSend::provider('---'));
        $this->assertSame(32, strlen(DidMyFormSend::provider(str_repeat('ab', 40))));
    }

    // 4. Intermediate failures send nothing; the last one sends "failed".

    public function test_a_failed_try_sends_nothing_and_the_final_failure_sends_failed_with_its_kind(): void
    {
        config([
            'mail.default' => 'cloudflare', 'mail.from.address' => 'hello@forms.example.test',
            'services.cloudflare_email.account_id' => 'acc123', 'services.cloudflare_email.token' => 'tok',
        ]);
        Http::fake([
            'api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['permanent_bounces' => ['owner@agency.example']]]),
        ]);
        $form = $this->form();
        $submission = $this->submission($form, 1);
        $mail = $this->mail($submission);

        foreach ([1, 2] as $try) {
            try {
                $this->sendNow($mail);
                $this->fail('the transport should refuse');
            } catch (DeliveryRefused $e) {
            }
            $this->assertSame([], $this->beats(), "try {$try} reported");
        }

        $mail->failed($e);
        $beats = $this->beats();
        $this->assertCount(1, $beats);
        $this->assertSame('failed', $beats[0]['body']['result']);
        $this->assertSame('bounced', $beats[0]['body']['provider_status']);
        $this->assertSame('cloudflare', $beats[0]['body']['provider']);
        $this->assertContract($beats[0]['body'], 'failed');
        $this->assertSame(1, (int) $submission->fresh()->emails_pending, 'the report changed no delivery state');

    }

    // 5. Several targets, mixed outcomes, in every order.

    public function test_one_bounced_target_ends_as_failed_whatever_order_the_outcomes_arrive_in(): void
    {
        $this->fakeMonitor();
        $form = $this->form();
        $kim = $this->recipient($form, 'kim@client.example');
        $lee = $this->recipient($form, 'lee@client.example');
        $bounce = new DeliveryRefused('bounced', DeliveryRefused::BOUNCED);

        $orders = [
            'sent, failed, sent' => ['send:owner', 'fail:kim', 'send:lee'],
            'failed first' => ['fail:kim', 'send:owner', 'send:lee'],
            'failed last' => ['send:owner', 'send:lee', 'fail:kim'],
        ];

        foreach ($orders as $name => $steps) {
            $submission = $this->submission($form, 3);
            $mails = ['owner' => $this->mail($submission), 'kim' => $this->mail($submission, $kim), 'lee' => $this->mail($submission, $lee)];
            $before = count($this->beats());

            foreach ($steps as $i => $step) {
                [$what, $who] = explode(':', $step);
                $what === 'send' ? $this->sendNow($mails[$who]) : $mails[$who]->failed($bounce);
                if ($i < 2) {
                    $this->assertCount($before, $this->beats(), "{$name}: reported before the last email settled");
                }
            }

            $new = array_slice($this->beats(), $before);
            $this->assertCount(1, $new, "{$name}: one report");
            $this->assertSame('failed', $new[0]['body']['result'], "{$name}: a working inbox hid the bounced one");
            $this->assertSame('bounced', $new[0]['body']['provider_status']);
        }

        $submission = $this->submission($form, 3);
        $before = count($this->beats());
        foreach ([null, $kim, $lee] as $recipient) {
            $this->sendNow($this->mail($submission, $recipient));
        }
        $new = array_slice($this->beats(), $before);
        $this->assertCount(1, $new);
        $this->assertSame('sent', $new[0]['body']['result']);
    }

    public function test_a_held_email_is_never_reported_and_does_not_block_the_report_of_the_rest(): void
    {
        $this->fakeMonitor();
        $form = $this->form();
        $this->recipient($form, 'kim@client.example');

        // The second email of the submission is over the form's limit of one a day.
        config(['mail.budget.per_form_per_day' => 1]);
        $this->submit($form)->assertOk();
        $this->assertSame(1, DeliveryFailure::where('kind', DeliveryFailure::HELD_FORM)->count());
        $this->assertSame(['sent'], array_column(array_column($this->beats(), 'body'), 'result'));

        // Both held: nothing went out, nothing is reported.
        $this->submit($form)->assertOk();
        $this->assertCount(1, $this->beats());
    }

    // 6. Send again recovers the monitor with a new event_id.

    public function test_send_again_after_a_failure_reports_sent_under_a_new_event_id(): void
    {
        $this->fakeMonitor();
        $form = $this->form();
        $submission = $this->submission($form, 1);

        $this->mail($submission)->failed(new DeliveryRefused('bounced', DeliveryRefused::BOUNCED));
        $this->assertSame(['failed'], array_column(array_column($this->beats(), 'body'), 'result'));
        $this->assertSame(['queued' => 1, 'dropped' => 0, 'left' => 0, 'limit_reached' => null], app(RetryDeliveries::class)->handle($form));

        // A second submission that fails and recovers: its ids differ from the first one's too.
        $other = $this->submission($form, 1);
        $this->mail($other)->failed(new \RuntimeException('provider down'));
        app(RetryDeliveries::class)->handle($form);

        $beats = array_column($this->beats(), 'body');
        $this->assertSame(['failed', 'sent', 'failed', 'sent'], array_column($beats, 'result'));
        $this->assertSame('error', $beats[2]['provider_status']);
        $this->assertCount(4, array_unique(array_column($beats, 'event_id')), 'each report has its own event_id');
        $this->assertSame(0, DeliveryFailure::count());
        $this->assertSame(0, (int) $submission->fresh()->emails_pending);
    }

    // 7. A monitor that is down never touches delivery.

    public function test_a_timeout_a_500_or_no_connection_leaves_delivery_alone(): void
    {
        $answers = [
            'timeout' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
            '500' => fn () => Http::response('oops', 500),
            'no connection' => fn () => throw new ConnectionException('Could not resolve host'),
        ];

        foreach ($answers as $name => $answer) {
            $this->answer = $answer;
            $form = $this->form(owner: 'owner-'.str_replace(' ', '-', $name).'@agency.example');

            $this->submit($form)->assertOk();

            $submission = $form->submissions()->latest('id')->first();
            $this->assertSame(0, (int) $submission->emails_pending, "{$name}: the email counts as sent");
            $this->assertSame(0, DeliveryFailure::count(), "{$name}: no delivery failure");
            $this->assertSame(0, DB::table('failed_jobs')->count(), "{$name}: a failed job");
            $this->assertNull($form->fresh()->monitor_refused_at, "{$name}: only 401 and 410 mark the key");
            $this->assertSame('ok', $form->fresh()->health()['status']);
        }
    }

    // 8. A refused key shows as a health problem until it is fixed.

    public function test_a_refused_key_is_a_health_problem_until_an_accepted_report_or_a_new_key(): void
    {
        foreach ([401, 410] as $status) {
            $this->fakeMonitor($status);
            $form = $this->form(owner: "owner{$status}@agency.example");
            $this->submit($form)->assertOk();

            $health = $form->fresh()->health();
            $this->assertSame('attention', $health['status']);
            $problem = collect($health['problems'])->firstWhere('code', 'MONITOR_KEY_REFUSED');
            $this->assertSame('DidMyFormSend refused the key saved on this form, so the monitor is not receiving reports about its emails.', $problem['message']);
            $this->assertStringContainsString('Paste a current key from the monitor\'s page on didmyformsend.com, or clear the DidMyFormSend key field', $problem['fix']);
            $this->app['auth']->forgetGuards();
            $this->getJson("/api/v1/forms/{$form->public_id}", ['Authorization' => 'Bearer '.$form->user->createToken('t')->plainTextToken])
                ->assertJsonPath('data.health.problems.0.code', 'MONITOR_KEY_REFUSED');

            if ($status === 401) {
                $this->fakeMonitor(202);
                $this->submit($form)->assertOk();
            } else {
                app(FormSettings::class)->update($form->fresh(), ['monitor_key' => self::OTHER_KEY]);
            }
            $this->assertNull($form->fresh()->monitor_refused_at, "{$status}: not cleared");
            $this->assertSame('ok', $form->fresh()->health()['status']);
        }

        // Removing the key clears it too, and no key means no problem shown.
        $form->forceFill(['monitor_refused_at' => now()])->save();
        app(FormSettings::class)->update($form, ['monitor_key' => null]);
        $this->assertSame('ok', $form->fresh()->health()['status']);
    }

    // 9. The key never leaves: only its prefix.

    public function test_the_key_appears_in_no_answer_log_line_job_or_serialized_model(): void
    {
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logs) {
            $logs[] = $e->message.' '.json_encode($e->context);
        });
        $form = $this->form();
        $headers = ['Authorization' => 'Bearer '.$form->user->createToken('t')->plainTextToken];

        $answers = [
            $this->getJson('/api/v1/forms', $headers)->assertJsonPath('data.0.monitor', ['on' => true, 'key_prefix' => 'fmh_Test'])->getContent(),
            $this->getJson("/api/v1/forms/{$form->public_id}", $headers)->getContent(),
            $this->patchJson("/api/v1/forms/{$form->public_id}", ['monitor_key' => self::KEY], $headers)->assertOk()->getContent(),
            json_encode($form->fresh()->toArray()), $form->fresh()->toJson(), serialize($form->fresh()),
            serialize(new SendMonitorHeartbeat($form->id, 'sent', null, 'smtp', time(), 'x')),
        ];
        FormHavenServer::actingAs($form->user)->tool(ListFormsTool::class)->assertOk()->assertSee('fmh_Test')->assertDontSee(self::KEY);
        FormHavenServer::actingAs($form->user)->tool(UpdateFormTool::class, ['form_id' => $form->public_id, 'monitor_key' => self::KEY])->assertOk()->assertDontSee(self::KEY);

        // A refusal, a 500 and an exception each write a log line.
        foreach ([fn () => Http::response('', 401), fn () => Http::response('', 500), fn () => throw new ConnectionException('timed out')] as $answer) {
            $this->answer = $answer;
            $this->submit($form)->assertOk();
        }
        $this->assertCount(3, $logs);
        foreach ($logs as $line) {
            $this->assertStringContainsString('fmh_Test', $line);
        }

        foreach ([...$answers, ...$logs] as $text) {
            $this->assertStringNotContainsString(self::KEY, $text);
            $this->assertStringNotContainsString(substr(self::KEY, 8), $text);
        }
    }

    // 10. Dashboard, REST and MCP set, clear and refuse the key the same way.

    public function test_dashboard_rest_and_mcp_set_clear_and_refuse_the_key_identically(): void
    {
        $form = $this->form(key: null);
        $user = $form->user;
        $headers = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
        $this->actingAs($user);
        $set = [
            'dashboard' => fn ($v) => Volt::test('forms.show', ['form' => $form->fresh()])->set('monitor_key', $v)->call('saveSettings'),
            'rest' => fn ($v) => $this->patchJson("/api/v1/forms/{$form->public_id}", ['monitor_key' => $v], $headers),
            'mcp' => fn ($v) => FormHavenServer::actingAs($user)->tool(UpdateFormTool::class, ['form_id' => $form->public_id, 'monitor_key' => $v]),
        ];
        $clear = [
            'dashboard' => fn () => Volt::test('forms.show', ['form' => $form->fresh()])->call('removeMonitorKey'),
            'rest null' => fn () => $this->patchJson("/api/v1/forms/{$form->public_id}", ['monitor_key' => null], $headers)->assertOk(),
            'rest empty' => fn () => $this->patchJson("/api/v1/forms/{$form->public_id}", ['monitor_key' => ''], $headers)->assertOk(),
            'mcp empty' => fn () => FormHavenServer::actingAs($user)->tool(UpdateFormTool::class, ['form_id' => $form->public_id, 'monitor_key' => ''])->assertOk(),
        ];

        foreach ($set as $how => $do) {
            $do('  '.self::KEY."\n");
            $this->assertSame(self::KEY, $form->fresh()->monitor_key, "{$how} did not set it");
            foreach ($clear as $way => $undo) {
                $undo();
                $this->assertNull($form->fresh()->monitor_key, "{$way} did not clear it");
                $do(self::KEY);
            }
            $clear['rest null']();
        }

        // A saved key survives saving the Settings tab with the box left empty.
        $set['rest'](self::KEY);
        Volt::test('forms.show', ['form' => $form->fresh()])->set('name', 'Renamed')->call('saveSettings')->assertHasNoErrors()
            ->assertSee('fmh_Test')->assertDontSee(self::KEY);
        $this->assertSame(self::KEY, $form->fresh()->monitor_key);

        foreach (['fmh_short', 'sk_live_'.str_repeat('a', 40), 'fmh_'.str_repeat('a', 39).'!'] as $bad) {
            $set['dashboard']($bad)->assertHasErrors(['monitor_key']);
            $this->assertSame(DidMyFormSend::KEY_MESSAGE, $set['dashboard']($bad)->errors()->first('monitor_key'));
            $set['rest']($bad)->assertUnprocessable()->assertJsonPath('errors.monitor_key.0', DidMyFormSend::KEY_MESSAGE);
            $set['mcp']($bad)->assertHasErrors([DidMyFormSend::KEY_MESSAGE]);
            $this->assertSame(self::KEY, $form->fresh()->monitor_key, "{$bad} replaced the key");
        }
    }

    // 11. At most five recipients, the first five in order.

    public function test_more_than_five_targets_sends_the_first_five(): void
    {
        $this->fakeMonitor();
        $form = $this->form();
        foreach (range(1, 5) as $i) {
            $this->recipient($form, "r{$i}@client.example");
        }
        $this->submit($form)->assertOk();

        $beats = $this->beats();
        $this->assertCount(1, $beats);
        $this->assertSame(['o***@agency.example', 'r***@client.example', 'r***@client.example', 'r***@client.example', 'r***@client.example'], array_column($beats[0]['body']['recipients'], 'masked'));
        $this->assertSame(array_map(fn ($e) => hash('sha256', $e), ['owner@agency.example', 'r1@client.example', 'r2@client.example', 'r3@client.example', 'r4@client.example']), array_column($beats[0]['body']['recipients'], 'sha256'));
        $this->assertContract($beats[0]['body'], 'six targets');
    }

    public function test_the_request_goes_only_to_the_configured_address(): void
    {
        config(['services.didmyformsend.url' => 'https://staging-monitor.example/']);
        Http::fake(['staging-monitor.example/*' => Http::response(['ok' => true], 202)]);
        $form = $this->form(['site_url' => 'https://evil.example', 'webhook_url' => null]);
        $this->submit($form)->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->url() === 'https://staging-monitor.example/api/v1/heartbeat');
        $this->assertSame([], $this->beats(), 'nothing went to the default address');
    }

    // Copy: the privacy paragraph and the surfaces that describe the setting.

    public function test_the_privacy_text_names_didmyformsend_only_for_a_form_with_a_key_in_each_language(): void
    {
        $form = $this->form(key: null);
        foreach (PrivacyText::LANGUAGES as $lang) {
            $this->assertStringNotContainsString('DidMyFormSend', PrivacyText::for($form, $lang)['paragraph']);
            $this->assertNull(PrivacyText::for($form, $lang)['facts']['delivery_reports_to']);
        }

        app(FormSettings::class)->update($form, ['monitor_key' => self::KEY]);
        $expect = [
            'en' => ['DidMyFormSend, a monitoring service also run by Example Forms B.V.,', 'Nothing you write in the form is sent to it.'],
            'nl' => ['DidMyFormSend, een monitoringdienst die ook door Example Forms B.V. wordt beheerd,', 'Niets wat je in het formulier schrijft wordt daarheen gestuurd.'],
            'de' => ['DidMyFormSend, einem ebenfalls von Example Forms B.V. betriebenen Überwachungsdienst,', 'Nichts, was Sie in das Formular schreiben, wird dorthin übermittelt.'],
        ];
        foreach ($expect as $lang => $phrases) {
            $text = PrivacyText::for($form->fresh(), $lang);
            foreach ($phrases as $phrase) {
                $this->assertStringContainsString($phrase, $text['paragraph'], $lang);
            }
            $this->assertSame('DidMyFormSend (didmyformsend.com)', $text['facts']['delivery_reports_to']);
            $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $text['paragraph']);
        }
    }

    public function test_every_surface_that_describes_the_setting_states_what_is_sent(): void
    {
        $llms = $this->get('/llms.txt')->assertOk()->getContent();
        foreach (['"monitor_key"', 'MONITOR_KEY_REFUSED:', 'cut to its first character and domain plus a SHA-256 hash', 'Nothing a visitor wrote', 'no request to DidMyFormSend at all'] as $phrase) {
            $this->assertStringContainsString($phrase, $llms);
        }

        $this->assertStringContainsString('DidMyFormSend key', \App\Support\ApiIndex::ENDPOINTS['PATCH /api/v1/forms/{id}']);
        $this->assertStringContainsString('DidMyFormSend key', FormHavenServer::tools()['update_form']);
        $this->assertStringContainsString('monitor_key', FormHavenServer::instructions());
        $schema = (new UpdateFormTool)->schema(new \Illuminate\JsonSchema\JsonSchemaTypeFactory);
        $this->assertArrayHasKey('monitor_key', $schema);

        foreach (['/legal/subprocessors', '/legal/privacy'] as $page) {
            $html = $this->get($page)->assertOk()->getContent();
            $this->assertStringContainsString('DidMyFormSend', $html, $page);
            $this->assertStringContainsString('first character and domain plus a SHA-256 hash of the full address', $html, $page);
            $this->assertStringContainsString('A hash of an email address is still personal data', $html, $page);
            $this->assertStringContainsString('run by Example Forms B.V.', $html, $page);
        }
    }

    public function test_the_settings_tab_shows_the_field_then_the_prefix_and_remove_then_the_refusal(): void
    {
        $form = $this->form(key: null);
        $this->actingAs($form->user);

        Volt::test('forms.show', ['form' => $form])->assertSee('DidMyFormSend key (optional)')->assertSee('Nothing a visitor wrote is sent.')
            ->assertSeeHtml('href="https://didmyformsend.com"')->assertDontSee('fmh_Test');

        app(FormSettings::class)->update($form, ['monitor_key' => self::KEY]);
        Volt::test('forms.show', ['form' => $form->fresh()])->assertSee('fmh_Test')->assertSee('Remove')
            ->assertDontSee(self::KEY)->assertDontSee('DidMyFormSend key (optional)');

        $form->forceFill(['monitor_refused_at' => now()])->save();
        Volt::test('forms.show', ['form' => $form->fresh()])->assertSee('DidMyFormSend refused this key')
            ->assertSee('DidMyFormSend refused the key saved on this form');
    }
}
