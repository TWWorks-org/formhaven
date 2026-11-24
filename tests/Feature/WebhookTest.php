<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhook;
use App\Models\Form;
use App\Support\WebhookTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Public test addresses, so no test depends on real DNS.
        WebhookTarget::$resolver = fn (string $host) => match ($host) {
            'hooks.example.com' => ['93.184.215.14'],
            'hooks.slack.com' => ['34.226.36.48'],
            'rebind.example.com' => ['93.184.215.14', '10.0.0.5'],
            'tailnet.example.com' => ['100.64.0.5'],
            default => [],
        };
    }

    protected function tearDown(): void
    {
        WebhookTarget::$resolver = null;
        parent::tearDown();
    }

    private function hookedForm(array $attributes = []): Form
    {
        return Form::factory()->create(['webhook_url' => 'https://hooks.example.com/in', 'webhook_secret' => 'whsec_test', ...$attributes]);
    }

    public function test_only_public_https_addresses_are_accepted(): void
    {
        $this->assertSame('93.184.215.14', WebhookTarget::check('https://hooks.example.com/in?key=1')->ip);
        $this->assertSame('hooks.example.com', WebhookTarget::check('https://HOOKS.Example.com/in')->host);
        $this->assertSame('2606:4700::1111', WebhookTarget::check('https://[2606:4700::1111]/in')->ip); // global unicast IPv6

        foreach ([
            'http://hooks.example.com/in' => 'https://',
            'https://127.0.0.1/' => 'private or local',
            'https://10.1.2.3/' => 'private or local',
            'https://192.168.1.10/' => 'private or local',
            'https://169.254.169.254/latest/meta-data' => 'private or local', // cloud metadata
            'https://[::1]/' => 'private or local',
            'https://tailnet.example.com/' => 'private or local', // Tailscale 100.64/10
            'https://rebind.example.com/' => 'private or local', // one public, one private answer
            'https://unknown.example.com/' => 'could not be found',
            'https://user:pass@hooks.example.com/' => 'credentials',
            'https://hooks.example.com\\@10.0.0.1/' => 'https://',
            // Spellings where the pinned name and curl's own lookup could differ.
            'https://hooks.example.com./in' => 'host name',
            'https://hööks.example.com/in' => 'host name',
            // IPv4 carried inside IPv6, and other non-global IPv6.
            'https://[::ffff:10.0.0.1]/' => 'private or local',
            'https://[::ffff:127.0.0.1]/' => 'private or local',
            'https://[64:ff9b::a00:1]/' => 'private or local',
            'https://[2002:a00:1::1]/' => 'private or local',
            'https://[fc00::1]/' => 'private or local',
            'https://[fe80::1]/' => 'private or local',
        ] as $url => $reason) {
            try {
                WebhookTarget::check($url);
                $this->fail("{$url} was accepted");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($reason, $e->getMessage(), $url);
            }
        }
    }

    /**
     * A webhook may not point at this server. The axis is how the target reaches the box: its IPv4
     * or IPv6 address typed in, a name that resolves to it, a configured range, our own host name.
     * An ordinary public host must stay allowed.
     */
    public function test_a_webhook_to_this_server_is_refused(): void
    {
        config(['app.url' => 'https://forms.example.net', 'services.webhooks.blocked_ips' => ['5.75.128.0/28', '2a01:4f9:1::/48']]);
        WebhookTarget::$resolver = fn (string $host) => match ($host) {
            'forms.example.net' => ['93.184.215.68', '2606:2800:220:1::7'],
            'alias.attacker.example' => ['93.184.215.68'],
            'v6alias.attacker.example' => ['2606:2800:220:1::7'],
            'range.attacker.example' => ['5.75.128.9'],
            'hooks.example.com' => ['93.184.215.14'],
            'next-door.example' => ['5.75.128.16'],
            default => [],
        };
        $own = 'The webhook address points to FormHaven\'s own server. Use the address of the service that should receive the submissions.';

        foreach ([
            'https://93.184.215.68/hook',            // APP_URL's IPv4, typed in
            'https://[2606:2800:220:1::7]/hook',         // APP_URL's IPv6, typed in
            'https://[2606:2800:220:1:0:0:0:7]/hook',  // the same IPv6, spelled out
            'https://alias.attacker.example/hook',  // a name that resolves to the server
            'https://v6alias.attacker.example/hook',
            'https://range.attacker.example/hook',  // inside WEBHOOK_BLOCKED_IPS
            'https://[2a01:4f9:1:ff::1]/hook',      // inside the IPv6 range
            'https://forms.example.net/f/abc123',   // our own host by name, e.g. another form's address
            'https://www.forms.example.net/hook',
        ] as $url) {
            try {
                WebhookTarget::check($url);
                $this->fail("{$url} was accepted");
            } catch (\InvalidArgumentException $e) {
                $this->assertSame($own, $e->getMessage(), $url);
            }
        }

        // Allowed: an ordinary public host, and the first address after the /28.
        $this->assertSame('93.184.215.14', WebhookTarget::check('https://hooks.example.com/in')->ip);
        $this->assertSame('5.75.128.16', WebhookTarget::check('https://next-door.example/in')->ip);

        // The owner sees it as the same kind of field error the other checks give.
        $form = Form::factory()->create();
        $this->patchJson("/api/v1/forms/{$form->public_id}", ['webhook_url' => 'https://alias.attacker.example/hook'], ['Authorization' => 'Bearer '.$form->user->createToken('test')->plainTextToken])
            ->assertUnprocessable()->assertJsonPath('errors.webhook_url.0', $own);
        $this->assertNull($form->fresh()->webhook_url);
    }

    public function test_behind_a_proxy_the_app_urls_addresses_can_be_left_out_and_the_box_listed_instead(): void
    {
        config(['app.url' => 'https://forms.example.net', 'services.webhooks.block_app_url_ips' => false, 'services.webhooks.blocked_ips' => ['93.184.215.99']]);
        WebhookTarget::$resolver = fn (string $host) => match ($host) {
            'forms.example.net' => ['104.16.1.1'],        // the proxy's shared address
            'other-site-on-proxy.example' => ['104.16.1.1'],
            'origin.attacker.example' => ['93.184.215.99'], // the box itself
            default => [],
        };

        $this->assertSame('104.16.1.1', WebhookTarget::check('https://other-site-on-proxy.example/in')->ip);
        $this->expectExceptionMessage('own server');
        WebhookTarget::check('https://origin.attacker.example/in');
    }

    public function test_a_submission_is_delivered_signed_with_every_field(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
        $form = $this->hookedForm(['name' => 'Contact', 'site_url' => 'https://client-site.nl']);

        $this->post("/f/{$form->public_id}", ['name' => 'Anna', 'email' => 'anna@example.org', 'interests' => ['Logo', 'Web']], ['Referer' => 'https://client-site.nl/contact?x=1'])
            ->assertRedirect();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($form) {
            [$t, $v1] = array_map(fn ($p) => explode('=', $p, 2)[1], explode(',', $request->header('FormHaven-Signature')[0]));
            $payload = json_decode($request->body(), true);

            return $request->url() === 'https://hooks.example.com/in'
                && $request->method() === 'POST'
                && $request->header('Content-Type')[0] === 'application/json'
                && hash_equals(hash_hmac('sha256', "{$t}.{$request->body()}", 'whsec_test'), $v1)
                && abs(time() - (int) $t) < 5
                && $payload['event'] === 'submission.created'
                && $payload['test'] === false
                && $payload['id'] === (string) $form->submissions()->sole()->id
                && $payload['form'] === ['id' => $form->public_id, 'name' => 'Contact']
                && $payload['reply_to'] === 'anna@example.org'
                && $payload['page'] === 'client-site.nl/contact'
                && $payload['fields'] === ['name' => 'Anna', 'email' => 'anna@example.org', 'interests' => ['Logo', 'Web']];
        });
        $this->assertSame(200, $form->fresh()->webhook_last_status);
    }

    public function test_the_test_event_has_the_same_shape_as_a_real_one(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('', 204)]);
        $form = $this->hookedForm();
        $this->actingAs($form->user);

        $this->post("/f/{$form->public_id}", ['name' => 'Anna', 'email' => 'anna@example.org', 'message' => 'Hi']);
        Volt::test('forms.show', ['form' => $form])->call('sendTestWebhook')->assertSee('Test event delivered: your endpoint answered HTTP 204.');

        [$real, $test] = collect(Http::recorded())->map(fn ($pair) => json_decode($pair[0]->body(), true))->all();
        $this->assertSame(array_keys($real), array_keys($test));
        $this->assertSame(array_keys($real['fields']), array_keys($test['fields']));
        $this->assertTrue($test['test']);
        $this->assertSame('test', $test['id']);
    }

    public function test_spam_is_not_sent_and_a_form_without_a_webhook_sends_nothing(): void
    {
        Http::fake();
        $hooked = $this->hookedForm();
        $plain = Form::factory()->create();

        $this->post("/f/{$hooked->public_id}", ['message' => 'Buy pills', '_gotcha' => 'x']);
        $this->post("/f/{$plain->public_id}", ['message' => 'Hi']);

        Http::assertNothingSent();
    }

    public function test_a_form_that_keeps_nothing_still_delivers_its_answers(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
        $form = $this->hookedForm(['store_submissions' => false]);

        $this->post("/f/{$form->public_id}", ['message' => 'Private but forwarded']);

        $this->assertSame([], $form->submissions()->sole()->data, 'wiped after the email');
        Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['fields'] === ['message' => 'Private but forwarded']);
    }

    public function test_invalid_utf8_is_repaired_not_a_crash_and_the_email_and_wipe_still_happen(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
        $form = $this->hookedForm(['store_submissions' => false]);

        $this->post("/f/{$form->public_id}", ['message' => "broken \xC3\x28 bytes", "na\xFFme" => 'Anna'])->assertRedirect();

        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages(), 'the email still went out');
        $this->assertSame([], $form->submissions()->sole()->data, 'and the answers were still wiped');
        Http::assertSent(fn (Request $request) => mb_check_encoding($request->body(), 'UTF-8')
            && str_contains(json_decode($request->body(), true)['fields']['message'], 'broken'));
    }

    public function test_a_failing_endpoint_is_retried_then_recorded_without_a_failed_job(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('nope', 500)]);
        $form = $this->hookedForm();
        $job = new DeliverWebhook($form->id, '{"event":"submission.created"}');

        $job->handle();
        $this->assertSame(500, $form->fresh()->webhook_last_status);
        $this->assertStringContainsString('answered HTTP 500', $form->fresh()->webhook_last_error);
        $this->assertSame(5, $job->tries);
        $this->assertSame(0, \DB::table('failed_jobs')->count());
    }

    public function test_redirects_are_not_followed(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);
        $form = $this->hookedForm();

        $this->assertFalse($form->deliverWebhook('{}'));
        Http::assertSentCount(1);
        $this->assertStringContainsString('HTTP 302', $form->fresh()->webhook_last_error);
    }

    public function test_the_owner_saves_a_webhook_gets_a_secret_and_cannot_point_it_inward(): void
    {
        $form = Form::factory()->create();
        $this->actingAs($form->user);

        Volt::test('forms.show', ['form' => $form])
            ->set('webhook_url', 'https://10.0.0.1/hook')
            ->call('saveSettings')
            ->assertHasErrors(['webhook_url'])
            ->set('webhook_url', 'https://hooks.example.com/in')
            ->call('saveSettings')
            ->assertHasNoErrors();

        $this->assertSame('https://hooks.example.com/in', $form->fresh()->webhook_url);
        $secret = $form->fresh()->webhook_secret;
        $this->assertStringStartsWith('whsec_', $secret);

        Volt::test('forms.show', ['form' => $form->fresh()])
            ->set('webhook_url', 'https://hooks.example.com/other')
            ->call('saveSettings')
            ->assertSee('Send a test event');
        $this->assertSame($secret, $form->fresh()->webhook_secret, 'changing the address keeps the secret');
    }

    public function test_submissions_queue_the_delivery_instead_of_waiting_for_it(): void
    {
        Queue::fake();
        $form = $this->hookedForm();

        $this->post("/f/{$form->public_id}", ['message' => 'Hi'])->assertRedirect();

        Queue::assertPushed(DeliverWebhook::class, fn ($job) => $job->formId === $form->id && str_contains($job->body, '"message":"Hi"'));
    }

    public function test_a_slack_address_gets_a_readable_message_with_visitor_text_escaped(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200), 'hooks.example.com/*' => Http::response('', 200)]);
        $slack = $this->hookedForm(['name' => 'Contact', 'webhook_url' => 'https://hooks.slack.com/services/T0/B0/xyz']);
        $plain = $this->hookedForm();

        $this->post("/f/{$slack->public_id}", ['name' => 'Anna <!channel>', 'first_name' => 'A & B', 'city' => 'Zoë in Düsseldorf', 'interests' => ['Logo', 'Web'], 'message' => "Line one\nLine two"], ['Referer' => 'https://client-site.nl/contact']);
        $this->post("/f/{$plain->public_id}", ['name' => 'Anna']);

        [$toSlack, $toPlain] = collect(Http::recorded())->map(fn ($pair) => $pair[0])->all();
        $message = json_decode($toSlack->body(), true);

        $this->assertSame(['text'], array_keys($message), 'Slack only accepts a text message');
        $this->assertStringStartsWith("*New submission: Contact*\nSent from client-site.nl/contact", $message['text']);
        $this->assertStringContainsString('*Name:* Anna &lt;!channel&gt;', $message['text'], 'no channel-wide ping from a visitor');
        $this->assertStringNotContainsString('<!channel>', $message['text']);
        $this->assertStringContainsString('*First name:* A &amp; B', $message['text']);
        $this->assertStringContainsString('*Interests:* Logo, Web', $message['text']);
        $this->assertStringContainsString('*City:* Zoë in Düsseldorf', $message['text'], 'accents arrive intact');
        $this->assertStringContainsString("*Message:* \nLine one\nLine two", $message['text']);
        $this->assertSame('submission.created', json_decode($toPlain->body(), true)['event'], 'every other address still gets the JSON');
    }

    public function test_slack_gets_the_subject_as_heading_a_labelled_test_event_and_a_note_when_nothing_is_kept(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);
        $form = $this->hookedForm(['name' => 'Quote', 'webhook_url' => 'https://hooks.slack.com/services/T0/B0/xyz']);
        $this->actingAs($form->user);

        $this->post("/f/{$form->public_id}", ['_subject' => 'New quote request', 'email' => 'anna@example.org']);
        Volt::test('forms.show', ['form' => $form])->call('sendTestWebhook')->assertSee('Test event delivered: your endpoint answered HTTP 200.');
        $this->assertTrue($form->fresh()->deliverWebhook(json_encode(['test' => false, 'form' => ['name' => 'Quote'], 'fields' => (object) []])));

        [$real, $test, $empty] = collect(Http::recorded())->map(fn ($pair) => json_decode($pair[0]->body(), true)['text'])->all();
        $this->assertStringStartsWith('*New quote request*', $real);
        $this->assertStringNotContainsString('Subject', $real);
        $this->assertStringStartsWith('*Test event from FormHaven: Quote*', $test);
        $this->assertStringContainsString('keeps no answers', $empty);
    }
}
