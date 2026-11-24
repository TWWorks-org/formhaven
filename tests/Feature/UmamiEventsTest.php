<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Umami;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Server-side Umami events (app/Support/Umami.php). A signup is counted once,
 * in the visitor's own session, with nothing that names them; nothing is sent
 * from another host, with Do Not Track, or while someone is signed in.
 */
class UmamiEventsTest extends TestCase
{
    use RefreshDatabase;

    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36';

    // Invented values: no test pins the live install.
    private const DOMAIN = 'forms.example.test';

    private const WEBSITE_ID = '00000000-0000-4000-8000-000000000001';

    private const COLLECT = 'http://analytics.example.test/api/send';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://'.self::DOMAIN,
            'services.umami.website_id' => self::WEBSITE_ID,
            'services.umami.collect_url' => self::COLLECT,
        ]);
    }

    public function test_an_install_with_no_website_id_or_no_collect_url_sends_nothing(): void
    {
        Http::fake();
        $this->visitor();

        config(['services.umami.website_id' => '']);
        User::factory()->create();
        $this->app->terminate();
        $this->assertNull(Umami::payload($this->visitor(), 'signup', '/register'));

        config(['services.umami.website_id' => self::WEBSITE_ID, 'services.umami.collect_url' => null]);
        User::factory()->create();
        $this->app->terminate();

        Http::assertNothingSent();
    }

    private function visitor(string $path = '/livewire/update', array $headers = []): Request
    {
        config(['services.umami.send_in_tests' => true]);
        $server = ['HTTP_USER_AGENT' => self::UA, 'HTTP_CF_CONNECTING_IP' => '203.0.113.7', 'HTTP_ACCEPT_LANGUAGE' => 'nl-NL,nl;q=0.9'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $request = Request::create('https://'.self::DOMAIN.$path, 'POST', [], [], [], $server);
        $this->app->instance('request', $request);

        return $request;
    }

    public function test_a_new_account_sends_one_signup_in_the_visitors_session_without_naming_them(): void
    {
        Http::fake();
        $this->visitor();

        User::factory()->create(['email' => 'someone@example.com', 'name' => 'Jane Example']);
        $this->app->terminate();

        Http::assertSentCount(1);
        Http::assertSent(function (SentRequest $sent) {
            $payload = $sent['payload'];

            return $sent->url() === self::COLLECT
                && $sent['type'] === 'event'
                && $payload['name'] === 'signup'
                && $payload['website'] === self::WEBSITE_ID
                && $payload['url'] === 'https://'.self::DOMAIN.'/register'
                && $payload['ip'] === '203.0.113.7'
                && $payload['userAgent'] === self::UA
                && $payload['data'] === ['method' => 'email']
                && ! str_contains($sent->body(), 'someone@example.com')
                && ! str_contains($sent->body(), 'Jane');
        });
    }

    public function test_a_google_signup_is_labelled_google(): void
    {
        $this->assertSame('google', Umami::signupMethod(Request::create('https://'.self::DOMAIN.'/auth/google/callback')));
        $this->assertSame('github', Umami::signupMethod(Request::create('https://'.self::DOMAIN.'/auth/github/callback')));
        $this->assertSame('email', Umami::signupMethod(Request::create('https://'.self::DOMAIN.'/livewire/update')));
    }

    public function test_nothing_is_sent_outside_production_unless_a_test_asks(): void
    {
        Http::fake();
        $this->app->instance('request', Request::create('https://'.self::DOMAIN.'/livewire/update', 'POST', [], [], [], ['HTTP_USER_AGENT' => self::UA]));

        User::factory()->create();
        $this->app->terminate();

        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_from_another_host(): void
    {
        Http::fake();
        config(['services.umami.send_in_tests' => true]);
        $this->app->instance('request', Request::create('https://staging.example.test/register', 'POST', [], [], [], ['HTTP_USER_AGENT' => self::UA]));

        User::factory()->create();
        $this->app->terminate();

        Http::assertNothingSent();
    }

    public function test_do_not_track_sends_nothing(): void
    {
        Http::fake();
        $this->visitor(headers: ['DNT' => '1']);

        User::factory()->create();
        $this->app->terminate();

        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_while_someone_is_signed_in(): void
    {
        $request = $this->visitor();
        $admin = User::factory()->make();
        $request->setUserResolver(fn () => $admin);

        $this->assertNull(Umami::payload($request, 'signup', '/register'));
    }

    public function test_event_data_that_could_name_someone_is_dropped(): void
    {
        $payload = Umami::payload($this->visitor(), 'signup', '/register', [
            'method' => 'email', 'email' => 'a@b.c', 'user_id' => 5, 'name' => 'Jane', 'plan' => 'pro', 'paid' => true,
        ]);

        $this->assertSame(['method' => 'email', 'plan' => 'pro', 'paid' => true], $payload['data']);
    }
}
