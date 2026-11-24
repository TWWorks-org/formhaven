<?php

namespace Tests\Feature;

use App\Actions\CreateForm;
use App\Http\Controllers\ContentController;
use App\Mail\MailBudgetSpent;
use App\Mail\SubmissionReceived;
use App\Mail\UsageNotice;
use App\Mcp\Servers\FormHavenServer;
use App\Mcp\Tools\CreateFormTool;
use App\Models\Form;
use App\Models\MonthlyUsage;
use App\Models\User;
use App\Support\Mode;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A stranger's clone: FORMHAVEN_MODE=self_hosted and a .env that names no operator. The env is
 * set in $_SERVER before parent::setUp() boots the app, because routes, the schedule and Cashier's
 * webhook route are registered once per boot from config('formhaven.mode'). Flipping the config
 * after boot would leave the hosted routes in place and prove nothing.
 */
class SelfHostedModeTest extends TestCase
{
    use RefreshDatabase;

    /** What a fresh .env from .env.example holds for these: nothing about anyone. */
    private const ENV = [
        'FORMHAVEN_MODE' => 'self_hosted',
        'ALLOW_REGISTRATION' => 'false',
        'APP_URL' => 'http://localhost',
        'LEGAL_ENTITY' => '',
        'LEGAL_KVK' => '',
        'LEGAL_ADDRESS' => '',
        'LEGAL_EMAIL' => '',
        'SUPPORT_EMAIL' => '',
        'LEGAL_COUNTRY_EN' => '',
        'LEGAL_COUNTRY_NL' => '',
        'LEGAL_COUNTRY_DE' => '',
        'LEGAL_HOSTING_PROVIDER' => '',
        'LEGAL_HOSTING_PLACE_EN' => '',
        'LEGAL_HOSTING_PLACE_NL' => '',
        'LEGAL_HOSTING_PLACE_DE' => '',
        'LEGAL_MAILER' => '',
        'OWNER_EMAILS' => '',
        'UMAMI_WEBSITE_ID' => '',
        'UMAMI_SCRIPT_URL' => '',
        'UMAMI_COLLECT_URL' => '',
        'INDEXNOW_KEY' => '',
        'GOOGLE_CLIENT_ID' => '',
        'GOOGLE_CLIENT_SECRET' => '',
        'MAIL_FROM_ADDRESS' => 'hello@example.com',
    ];

    /** Text that must never reach a page, mail or agent answer on a self-hosted install. */
    private const FORBIDDEN = [
        '/Example Forms B\.V\./', '/12345678/', '/formhaven\.net/i', '/\btrial\b/i', '/€|\bEUR\b|per form a month|never fewer than/i',
        '/umami|data-website-id/i', '/More from the same maker|queryscope\.dev/i', '/fonts\.bunny\.net/',
        '/Hetzner|Falkenstein|Cloudflare, Inc|Germany/', '/\bSubscribe\b|\bBilling\b/',
    ];

    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (self::ENV as $name => $value) {
            $this->savedEnv[$name] = array_key_exists($name, $_SERVER) ? [$_SERVER[$name]] : null;
            $_SERVER[$name] = $value;
        }

        parent::setUp();

        // Dotenv may overwrite a $_SERVER value it loaded itself on an earlier boot in this process
        // (its immutable writer only protects variables it did not load), so a value the local .env
        // also sets, such as APP_URL, is set again in config here. FORMHAVEN_MODE is pinned by
        // phpunit.xml before any .env load, so the $_SERVER value above is what routes were built from.
        config([
            'app.url' => 'http://localhost',
            'mail.from.address' => 'hello@example.com',
            'legal' => ['email' => 'hello@example.com', 'support_email' => 'hello@example.com', 'country' => ['en' => null, 'nl' => null, 'de' => null], 'hosting' => ['provider' => null, 'place' => ['en' => null, 'nl' => null, 'de' => null]]] + config('legal'),
            'legal.entity' => null, 'legal.kvk' => null, 'legal.address' => null, 'legal.mailer' => null,
            'billing.owner_emails' => [],
            'services.umami' => ['website_id' => null, 'script_url' => null, 'collect_url' => null],
            'services.indexnow.key' => null,
            'services.google.client_id' => null, 'services.google.client_secret' => null,
        ]);
        // The console request was built from the booted APP_URL; point the URL generator at the stranger's.
        \Illuminate\Support\Facades\URL::forceRootUrl('http://localhost');
        $this->assertSame('self_hosted', config('formhaven.mode'), 'FORMHAVEN_MODE must reach the app before it boots');

        Mail::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->savedEnv as $name => $saved) {
            if ($saved === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $saved[0];
            }
        }
    }

    /**
     * Every route that only the hosted service registers (routes/web.php, Cashier), as GET URLs.
     * Shared with HostedModeControlTest, which proves the same list answers 200 there.
     *
     * @return list<string>
     */
    public static function hostedOnlyGetUrls(): array
    {
        return array_merge(
            ['/pricing', '/templates', '/guides', '/report', '/legal/privacy', '/legal/terms', '/legal/dpa', '/legal/subprocessors'],
            array_map(fn ($slug) => "/templates/{$slug}", array_keys(ContentController::templates())),
            array_map(fn ($slug) => "/alternatives/{$slug}", array_keys(ContentController::alternatives())),
            array_map(fn ($slug) => "/guides/{$slug}", array_keys(ContentController::guides())),
        );
    }

    /** @return list<string> the route names behind hostedOnlyGetUrls(), plus the POST-only and signed-in ones */
    public static function hostedOnlyRouteNames(): array
    {
        return ['pricing', 'templates.index', 'templates.show', 'alternatives.show', 'guides.index', 'guides.show', 'report', 'report.store', 'legal', 'billing', 'demo', 'cashier.webhook'];
    }

    private function token(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function assertClean(string $body, string $what): void
    {
        foreach (self::FORBIDDEN as $pattern) {
            $this->assertDoesNotMatchRegularExpression($pattern, $body, "{$what} contains {$pattern}");
        }
    }

    public function test_the_app_booted_in_self_hosted_mode(): void
    {
        $this->assertSame('self_hosted', config('formhaven.mode'));
        $this->assertTrue(Mode::selfHosted());
        $this->assertEmpty(config('legal.entity'), 'no operator name in code');
    }

    #[DataProvider('ages')]
    public function test_an_old_account_still_receives_submissions_and_creates_forms(int $days): void
    {
        $user = User::factory()->create();
        $this->assertNull($user->fresh()->trial_ends_at, 'a self-hosted account has no trial');
        $form = Form::factory()->for($user)->create();

        $this->travel($days)->days();

        $this->postJson("/f/{$form->public_id}", ['email' => 'visitor@example.org', 'message' => 'Hello'])
            ->assertOk()->assertJsonPath('ok', true);
        Mail::assertQueued(SubmissionReceived::class, 1);

        // The dashboard, REST and MCP all go through CreateForm.
        app(CreateForm::class)->handle($user->fresh(), ['name' => 'From the dashboard']);
        $this->withHeaders($this->token($user))->postJson('/api/v1/forms', ['name' => 'From REST'])->assertCreated();
        FormHavenServer::actingAs($user->fresh())->tool(CreateFormTool::class, ['name' => 'From MCP'])->assertOk();

        $this->assertSame(4, $user->forms()->count());
    }

    public static function ages(): array
    {
        return ['15 days' => [15], '60 days' => [60], '400 days' => [400]];
    }

    public function test_nothing_is_paused_past_every_hosted_limit_and_no_usage_notice_goes_out(): void
    {
        $user = User::factory()->create();
        // More forms than a trial covers, and more submissions than any trial or pool includes.
        $forms = Form::factory()->count(30)->for($user)->create();
        MonthlyUsage::add($user->id, now(), 60000, 5);

        $this->travel(90)->days();
        MonthlyUsage::add($user->id, now(), 60000, 5);

        foreach ([$forms->first(), $forms->last()] as $form) {
            $this->postJson("/f/{$form->public_id}", ['email' => 'visitor@example.org', 'message' => 'Still here'])
                ->assertOk()->assertJsonPath('ok', true);
            $this->post("/f/{$form->public_id}", ['message' => 'From a plain HTML form'])->assertRedirect();
        }

        $this->assertNull($user->fresh()->plan()['submissions']);
        $this->assertFalse($user->fresh()->emailsPaused());
        Mail::assertQueued(SubmissionReceived::class, 4);
        Mail::assertNotQueued(UsageNotice::class);

        $this->actingAs($user)->get('/forms')->assertOk()->assertDontSee('paused')->assertSee('no limit');
    }

    public function test_the_address_opens_the_login_page_or_the_dashboard(): void
    {
        $this->get('/')->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())->get('/')->assertRedirect(route('forms.index'));
    }

    public function test_every_hosted_only_url_is_not_found(): void
    {
        $user = User::factory()->create();

        foreach (self::hostedOnlyRouteNames() as $name) {
            $this->assertFalse(Route::has($name), "route {$name} is registered");
        }

        foreach (self::hostedOnlyGetUrls() as $url) {
            $this->get($url)->assertNotFound();
            $this->actingAs($user)->get($url)->assertNotFound();
            auth()->logout();
        }

        $this->actingAs($user)->get('/billing')->assertNotFound();
        auth()->logout();
        $this->post('/report', ['url' => 'https://example.org', 'reason' => str_repeat('Phishing form. ', 3), 'name' => 'A', 'email' => 'a@example.org', 'good_faith' => 1])->assertNotFound();
        $this->post('/demo', ['message' => 'Hi'])->assertNotFound();
        $this->postJson('/stripe/webhook', ['type' => 'customer.subscription.updated'])->assertNotFound();
        $this->get('/sitemap.xml')->assertNotFound();
    }

    public function test_the_pages_mails_and_agent_answers_that_remain_name_no_operator_price_or_trial(): void
    {
        // Guest pages, with registration still open (no account yet).
        $this->assertClean($this->get('/register')->assertOk()->getContent(), '/register');
        $this->assertClean($this->get('/login')->assertOk()->getContent(), '/login');
        $this->assertClean($this->get('/no-such-page')->assertNotFound()->getContent(), 'the 404 page');
        $this->assertClean($this->get('/f/thanks')->assertOk()->getContent(), 'the thank-you page');

        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create(['name' => 'Contact form']);
        $headers = $this->token($user);

        $this->assertClean($this->get('/login')->assertOk()->getContent(), '/login with an account');
        foreach (['/forms', "/forms/{$form->id}", '/api-keys', '/settings/profile'] as $page) {
            $this->assertClean($this->actingAs($user)->get($page)->assertOk()->getContent(), $page);
        }

        // The OAuth consent screen an MCP client sends the owner to.
        $client = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']])->assertCreated()->json('client_id');
        $consent = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client, 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback', 'response_type' => 'code', 'state' => 'xyz',
            'code_challenge' => str_repeat('A', 43), 'code_challenge_method' => 'S256', 'resource' => url('/mcp'), 'scope' => 'mcp:use',
        ]))->assertOk()->getContent();
        $this->assertClean($consent, 'the OAuth consent screen');
        auth()->logout();

        // A real submission's emails, to the owner and to a confirmed recipient, and the account emails.
        $this->postJson("/f/{$form->public_id}", ['email' => 'visitor@example.org', 'message' => 'Hello there']);
        $submission = $form->submissions()->first();
        $this->assertClean((new SubmissionReceived($submission))->render(), 'the submission email');
        $this->assertClean((string) (new VerifyEmail)->toMail($user)->render(), 'the verify-email mail');

        // Text for agents and crawlers.
        $this->assertClean($this->get('/llms.txt')->assertOk()->getContent(), 'llms.txt');
        $this->assertClean($this->get('/robots.txt')->assertOk()->getContent(), 'robots.txt');
        $this->assertClean($this->get('/api')->assertOk()->getContent(), '/api');
        $this->assertClean($this->postJson('/api/v1/start', ['name' => 'Agent form'], $headers)->assertCreated()->getContent(), 'the start answer');
        $this->assertClean($this->getJson("/api/v1/forms/{$form->public_id}/privacy-text?lang=en", $headers)->assertOk()->getContent(), 'the privacy text');

        $mcp = fn (array $body) => $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1] + $body, $headers + ['Accept' => 'application/json, text/event-stream'])->assertOk()->getContent();
        $initialize = $mcp(['method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]]);
        $this->assertStringContainsString('submissions are stored on this install', $initialize);
        $this->assertClean($initialize, 'the MCP instructions');
        $tools = $mcp(['method' => 'tools/list', 'params' => []]);
        $this->assertStringContainsString('list_forms', $tools);
        $this->assertClean($tools, 'the MCP tool descriptions');
        $this->assertClean($mcp(['method' => 'tools/call', 'params' => ['name' => 'list_forms', 'arguments' => []]]), 'list_forms');
    }

    public function test_every_internal_link_on_the_remaining_pages_resolves(): void
    {
        $guestPages = ['/login', '/register', '/forgot-password', '/no-such-page'];
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $userPages = ['/forms', "/forms/{$form->id}", '/api-keys', '/settings/profile', '/settings/password', '/settings/appearance'];

        $checked = 0;
        foreach (['guest' => $guestPages, 'user' => $userPages] as $who => $pages) {
            foreach ($pages as $page) {
                $who === 'user' ? $this->actingAs($user) : auth()->logout();
                preg_match_all('/<a\b[^>]*\bhref="([^"]+)"/i', $this->get($page)->getContent(), $matches);

                foreach (array_unique($matches[1]) as $href) {
                    $href = html_entity_decode($href);
                    if (str_starts_with($href, '#') || preg_match('#^(mailto|javascript|tel):#', $href)) {
                        continue;
                    }
                    $parts = parse_url($href);
                    if (isset($parts['host']) && $parts['host'] !== 'localhost') {
                        $this->fail("{$page} links to another site: {$href}");
                    }
                    $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

                    $who === 'user' ? $this->actingAs($user) : auth()->logout();
                    $status = $this->get($path)->getStatusCode();
                    $this->assertLessThan(400, $status, "{$page} links to {$path}, which answers {$status}");
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(10, $checked);
    }

    public function test_registration_is_open_for_the_first_account_only(): void
    {
        $page = $this->get('/register')->assertOk();
        $page->assertSee('Create account')->assertDontSee('terms of service');
        $this->get('/login')->assertSee('href="'.route('register').'"', false);

        Volt::test('auth.register')
            ->set('name', 'First Owner')->set('email', 'owner@example.org')
            ->set('password', 'password')->set('password_confirmation', 'password')
            ->call('register')->assertHasNoErrors();
        $owner = User::where('email', 'owner@example.org')->firstOrFail();
        $this->assertNull($owner->terms_accepted_at, 'a self-hosted install has no terms to accept');
        auth()->logout();

        $this->get('/register')->assertForbidden()->assertSee('Registration is closed on this install');
        $this->getJson('/register')->assertForbidden()->assertJsonPath('code', Mode::REGISTRATION_CLOSED);
        $this->get('/login')->assertOk()->assertDontSee('href="'.route('register').'"', false)->assertDontSee('Sign up');

        // A request sent to the component directly, past the closed page.
        Volt::test('auth.register')
            ->set('name', 'Stranger')->set('email', 'stranger@example.org')
            ->set('password', 'password')->set('password_confirmation', 'password')
            ->call('register')->assertHasErrors('email');
        $this->assertSame(1, User::count());

        config(['formhaven.allow_registration' => true]);
        $this->get('/register')->assertOk()->assertSee('Create account');
        $this->get('/login')->assertSee('href="'.route('register').'"', false);
    }

    public function test_google_sign_in_logs_in_the_account_but_makes_no_new_one_once_registration_is_closed(): void
    {
        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
        $owner = User::factory()->create(['email' => 'owner@example.org']);
        $google = fn (string $email) => (new GoogleUser)->map([
            'id' => 'g-'.$email, 'name' => 'Someone', 'email' => $email, 'user' => ['email_verified' => true],
        ])->setRaw(['email_verified' => true]);
        Socialite::shouldReceive('driver->user')->andReturn($google('stranger@example.org'), $google('owner@example.org'));

        $this->get('/auth/google/callback')->assertRedirect(route('login'))->assertSessionHasErrors('google');
        $this->assertSame(1, User::count());
        $this->assertGuest();

        $this->get('/auth/google/callback')->assertRedirect(route('forms.index'));
        $this->assertAuthenticatedAs($owner);
    }

    public function test_the_start_flow_follows_the_registration_rule(): void
    {
        $this->postJson('/api/v1/start', ['name' => 'First form'])->assertCreated()
            ->assertJsonPath('data.status', 'waiting_for_owner');

        $owner = User::factory()->create();
        $this->postJson('/api/v1/start', ['name' => 'Stranger form'])->assertForbidden()
            ->assertJsonPath('code', Mode::REGISTRATION_CLOSED)
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, 'Registration is closed on this install'))->etc());

        // The existing account's agent, with its key, still reserves forms.
        $this->postJson('/api/v1/start', ['name' => 'Owner form'], $this->token($owner))->assertCreated();

        config(['formhaven.allow_registration' => true]);
        $this->postJson('/api/v1/start', ['name' => 'Open again'])->assertCreated();
    }

    public function test_the_claim_page_offers_no_sign_up_once_registration_is_closed(): void
    {
        $claimUrl = $this->postJson('/api/v1/start', ['name' => 'Reserved early'])->assertCreated()->json('data.claim_url');
        $path = parse_url($claimUrl, PHP_URL_PATH);
        $this->get($path)->assertOk()->assertSee('Create an account');

        $owner = User::factory()->create();
        $page = $this->get($path)->assertOk()->assertDontSee('Create an account');
        $this->assertClean($page->getContent(), 'the claim page');

        // The existing owner can still activate a form reserved before registration closed.
        $this->actingAs($owner)->post($path)->assertRedirect();
        $this->assertSame(1, $owner->forms()->count());
    }

    public function test_no_billing_command_is_scheduled(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('digest:send')
            ->doesntExpectOutputToContain('billing:charge-extra')
            ->assertExitCode(0);
    }

    public function test_the_sitemap_command_writes_an_empty_sitemap_and_succeeds(): void
    {
        $dir = sys_get_temp_dir().'/formhaven-sitemap-'.uniqid();
        mkdir($dir);
        app()->usePublicPath($dir);

        $this->artisan('sitemap:generate')->assertExitCode(0);

        $xml = file_get_contents($dir.'/sitemap.xml');
        $this->assertStringContainsString('<urlset', $xml);
        $this->assertStringNotContainsString('<loc>', $xml);
        unlink($dir.'/sitemap.xml');
        rmdir($dir);
    }

    public function test_robots_txt_keeps_every_crawler_out(): void
    {
        $this->assertSame("User-agent: *\nDisallow: /\n", $this->get('/robots.txt')->assertOk()->getContent());
    }

    public function test_the_privacy_text_names_only_what_the_install_configured(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $headers = $this->token($user);

        foreach (['en', 'nl', 'de'] as $lang) {
            $text = $this->getJson("/api/v1/forms/{$form->public_id}/privacy-text?lang={$lang}", $headers)->assertOk();
            $this->assertClean($text->getContent(), "the {$lang} privacy text");
            $this->assertNull($text->json('data.facts.dpa'));
            $this->assertNull($text->json('data.facts.backup_copies_kept_days'));
        }
        $this->getJson("/api/v1/forms/{$form->public_id}/privacy-text?lang=en", $headers)
            ->assertJsonPath('data.facts.processor', 'FormHaven, run by the site owner on their own install')
            ->assertJsonPath('data.facts.stored_at', 'this FormHaven install')
            ->assertJson(fn ($json) => $json->where('data.paragraph', fn ($p) => str_contains($p, 'our own installation stores the messages'))->etc());

        // An agency running the install for its clients names itself and its servers.
        config(['legal.entity' => 'Example Agency', 'legal.country.en' => 'Belgium', 'legal.hosting.provider' => 'Example Hosting', 'legal.hosting.place.en' => 'Ghent, Belgium', 'legal.mailer' => 'Example Mail']);
        $paragraph = $this->getJson("/api/v1/forms/{$form->public_id}/privacy-text?lang=en", $headers)->json('data.paragraph');
        $this->assertStringContainsString('run by Example Agency in Belgium', $paragraph);
        $this->assertStringContainsString('on servers of Example Hosting in Ghent, Belgium', $paragraph);
        $this->assertStringContainsString('sent through Example Mail', $paragraph);
    }

    public function test_the_didmyformsend_key_works_here_and_names_no_operator_it_cannot_know(): void
    {
        $key = 'fmh_'.str_repeat('Ab3', 13).'x';
        $user = User::factory()->create(['email' => 'me@my-agency.example']);
        $form = Form::factory()->for($user)->create();
        $headers = $this->token($user);

        $this->patchJson("/api/v1/forms/{$form->public_id}", ['monitor_key' => $key], $headers)->assertOk()
            ->assertJsonPath('data.monitor', ['on' => true, 'key_prefix' => substr($key, 0, 8)])
            ->assertDontSee($key);

        // An agency that names itself on its own install still does not run DidMyFormSend.
        config(['legal.entity' => 'Example Agency']);
        $phrases = ['en' => 'DidMyFormSend, a separate monitoring service,', 'nl' => 'DidMyFormSend, een aparte monitoringdienst,', 'de' => 'DidMyFormSend, einem separaten Überwachungsdienst,'];
        foreach ($phrases as $lang => $phrase) {
            $text = $this->getJson("/api/v1/forms/{$form->public_id}/privacy-text?lang={$lang}", $headers)->assertOk();
            $this->assertStringContainsString($phrase, $text->json('data.paragraph'));
            $this->assertStringNotContainsString('Example Agency betrieben', $text->json('data.paragraph'));
            $this->assertStringNotContainsString('also run by', $text->json('data.paragraph'));
            $this->assertClean($text->getContent(), "the {$lang} privacy text with a key");
        }
        config(['legal.entity' => null]);

        $this->assertStringContainsString('monitor_key', FormHavenServer::instructions());

        // The report itself goes out the same way as on the hosted service.
        \Illuminate\Support\Facades\Http::fake(['didmyformsend.com/*' => \Illuminate\Support\Facades\Http::response(['ok' => true], 202)]);
        $submission = $form->submissions()->create(['data' => ['name' => 'Anna']]);
        \App\Support\DidMyFormSend::afterMail($submission, true, null, 'smtp');
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => $r->url() === 'https://didmyformsend.com/api/v1/heartbeat'
            && $r['result'] === 'sent' && $r['recipients'][0]['masked'] === 'm***@my-agency.example' && $r->hasHeader('Authorization', 'Bearer '.$key));

        // Removing it stops everything.
        $this->patchJson("/api/v1/forms/{$form->public_id}", ['monitor_key' => ''], $headers)->assertOk()->assertJsonPath('data.monitor.on', false);
        \App\Support\DidMyFormSend::afterMail($submission->fresh(), true, null, 'smtp');
        \Illuminate\Support\Facades\Http::assertSentCount(1);
    }

    public function test_the_operator_notice_goes_to_the_first_account_when_owner_emails_is_empty(): void
    {
        config(['mail.budget.notifications_per_day' => 1, 'mail.budget.per_form_per_day' => 100, 'mail.budget.notices_per_day' => 10]);
        $first = User::factory()->create(['email' => 'first@example.org']);
        $form = Form::factory()->for(User::factory()->create())->create();

        $this->postJson("/f/{$form->public_id}", ['message' => 'One']);
        $this->postJson("/f/{$form->public_id}", ['message' => 'Two']);

        Mail::assertQueued(MailBudgetSpent::class, fn ($mail) => $mail->hasTo('first@example.org') && $first->exists);
    }
}
