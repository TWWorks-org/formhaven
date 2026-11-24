<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\OAuthClient;
use App\Models\OAuthRefreshToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Signing an MCP client in with OAuth, as Claude and other MCP clients do it. */
class OAuthTest extends TestCase
{
    use RefreshDatabase;

    private const CLAUDE = 'https://claude.ai/api/mcp/auth_callback';

    private function register(array $uris = [self::CLAUDE], string $name = 'Claude'): string
    {
        return $this->postJson('/oauth/register', ['client_name' => $name, 'redirect_uris' => $uris])
            ->assertCreated()->assertJsonPath('token_endpoint_auth_method', 'none')->json('client_id');
    }

    /** @return array{0: string, 1: string} verifier, challenge */
    private function pkce(): array
    {
        $verifier = str_repeat('a1B2c3D4e5', 5);

        return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
    }

    private function authorizeQuery(string $clientId, string $challenge, string $redirect = self::CLAUDE): array
    {
        return [
            'client_id' => $clientId, 'redirect_uri' => $redirect, 'response_type' => 'code', 'state' => 'xyz',
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'resource' => url('/mcp'), 'scope' => 'mcp:use',
        ];
    }

    /** Runs consent as $user and returns the code from the redirect. */
    private function code(User $user, string $clientId, string $challenge, string $redirect = self::CLAUDE): string
    {
        $query = $this->authorizeQuery($clientId, $challenge, $redirect);
        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($query))->assertOk()->assertSee('Connect Claude to FormHaven');
        $location = $this->actingAs($user)->post('/oauth/authorize', $query + ['decision' => 'allow'])->assertRedirect()->headers->get('Location');

        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $this->assertSame('xyz', $params['state']);
        $this->assertSame(rtrim(url('/'), '/'), $params['iss']);
        $this->assertStringStartsWith($redirect, $location);
        $this->app['auth']->forgetGuards();

        return $params['code'];
    }

    private function exchange(string $code, string $clientId, string $verifier, string $redirect = self::CLAUDE)
    {
        // Form-encoded, as RFC 6749 and Claude send it.
        return $this->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $clientId,
            'redirect_uri' => $redirect, 'code_verifier' => $verifier, 'resource' => url('/mcp'),
        ]);
    }

    public function test_discovery_documents_match_what_claude_reads(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');

        $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()
            ->assertJsonPath('resource', url('/mcp'))
            ->assertJsonPath('authorization_servers', [rtrim(url('/'), '/')]);

        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
            ->assertJsonPath('issuer', rtrim(url('/'), '/'))
            ->assertJsonPath('code_challenge_methods_supported', ['S256'])
            ->assertJsonPath('registration_endpoint', url('/oauth/register'))
            ->assertJsonPath('token_endpoint', url('/oauth/token'))
            ->assertJsonPath('authorization_endpoint', url('/oauth/authorize'))
            ->assertJsonPath('authorization_response_iss_parameter_supported', true);
    }

    public function test_the_full_sign_in_gives_a_token_that_works_on_mcp_and_refreshes(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $client = $this->register();
        [$verifier, $challenge] = $this->pkce();

        $tokens = $this->exchange($this->code($user, $client, $challenge), $client, $verifier)
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('token_type', 'Bearer')->assertJsonPath('expires_in', 3600)->json();

        $mcp = fn (string $token) => $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_forms', 'arguments' => []]], ['Authorization' => 'Bearer '.$token]);
        $mcp($tokens['access_token'])->assertOk()->assertSee($form->public_id);

        // The token is for the MCP server only.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/forms', ['Authorization' => 'Bearer '.$tokens['access_token']])->assertForbidden();

        // Refresh rotates: a new pair, and the old access token stops working.
        $this->app['auth']->forgetGuards();
        $new = $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client])->assertOk()->json();
        $this->assertNotSame($tokens['refresh_token'], $new['refresh_token']);
        $this->app['auth']->forgetGuards();
        $mcp($tokens['access_token'])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $mcp($new['access_token'])->assertOk();

        // Replaying the old refresh token ends the whole sign-in.
        $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->app['auth']->forgetGuards();
        $mcp($new['access_token'])->assertUnauthorized();
    }

    public function test_an_expired_access_token_gets_a_401_with_the_metadata_pointer(): void
    {
        $user = User::factory()->create();
        $client = $this->register();
        [$verifier, $challenge] = $this->pkce();
        $tokens = $this->exchange($this->code($user, $client, $challenge), $client, $verifier)->json();

        $this->travel(61)->minutes();
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization' => 'Bearer '.$tokens['access_token']])
            ->assertUnauthorized()->assertHeader('WWW-Authenticate');
    }

    public function test_a_wrong_verifier_a_reused_code_and_an_expired_code_are_refused(): void
    {
        $user = User::factory()->create();
        $client = $this->register();
        [$verifier, $challenge] = $this->pkce();

        $code = $this->code($user, $client, $challenge);
        $this->exchange($code, $client, str_repeat('wrong', 10))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        $code = $this->code($user, $client, $challenge);
        $first = $this->exchange($code, $client, $verifier)->assertOk()->json();
        $this->exchange($code, $client, $verifier)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        // The reuse revoked what the first exchange issued.
        $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token'], 'client_id' => $client])
            ->assertStatus(400);

        $code = $this->code($user, $client, $challenge);
        $this->travel(3)->minutes();
        $this->exchange($code, $client, $verifier)->assertStatus(400)->assertJsonPath('error_description', 'The code has expired. Sign in again.');
    }

    public function test_redirect_uris_must_match_except_the_loopback_port(): void
    {
        $user = User::factory()->create();
        [, $challenge] = $this->pkce();
        $claude = $this->register();

        // A different path is not the registered redirect: shown as an error, never redirected to.
        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($this->authorizeQuery($claude, $challenge, 'https://claude.ai/api/mcp/evil')))
            ->assertStatus(400)->assertSee('This sign-in did not work');

        // Claude Code registers a loopback URL and comes back on another port.
        $cli = $this->register(['http://localhost:3118/callback'], 'Claude Code');
        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($this->authorizeQuery($cli, $challenge, 'http://localhost:51234/callback')))
            ->assertOk()->assertSee('a program on this computer');
        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($this->authorizeQuery($cli, $challenge, 'http://localhost:51234/other')))
            ->assertStatus(400);
        // Addresses a browser and PHP read differently never match, even against a loopback registration.
        // (Surrounding whitespace is not tested here: Laravel trims every input, so the checked and redirected address match.)
        foreach (['http://localhost:1\\@evil.example/callback', 'http://x@localhost:2/callback', 'http://localhost:3\\/callback'] as $tricky) {
            $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($this->authorizeQuery($cli, $challenge, $tricky)))->assertStatus(400);
        }
    }

    public function test_registration_refuses_unsafe_redirects(): void
    {
        foreach ([[], ['http://evil.example/cb'], ['javascript:alert(1)'], ['https://x.example/cb#frag'],
            ['http://localhost\\@evil.example/cb'], ['http://localhost:1@evil.example/cb'], ['https://user@claude.ai/cb'], ["https://claude.ai/c b"], ['https://bad_host!/cb']] as $uris) {
            $this->postJson('/oauth/register', ['client_name' => 'X', 'redirect_uris' => $uris])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        }
        $this->postJson('/oauth/register', ['redirect_uris' => ['http://127.0.0.1/callback']])->assertCreated()->assertJsonPath('client_name', 'MCP client');
    }

    public function test_authorize_needs_pkce_and_our_resource_and_a_guest_logs_in_first(): void
    {
        $user = User::factory()->create();
        $client = $this->register();
        [, $challenge] = $this->pkce();
        $query = $this->authorizeQuery($client, $challenge);

        $this->get('/oauth/authorize?'.http_build_query($query))->assertRedirect(route('login'));

        $location = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query(['code_challenge_method' => 'plain'] + $query))->headers->get('Location');
        $this->assertStringContainsString('error=invalid_request', $location);

        $location = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query(['resource' => 'https://other.example/mcp'] + $query))->headers->get('Location');
        $this->assertStringContainsString('error=invalid_target', $location);

        $location = $this->actingAs($user)->post('/oauth/authorize', $query + ['decision' => 'deny'])->headers->get('Location');
        $this->assertStringContainsString('error=access_denied', $location);
        $this->assertStringContainsString('state=xyz', $location);
    }

    public function test_a_signed_in_app_stays_listed_after_its_hour_long_key_expires_and_disconnect_ends_it(): void
    {
        $user = User::factory()->create();
        $client = $this->register();
        [$verifier, $challenge] = $this->pkce();
        $tokens = $this->exchange($this->code($user, $client, $challenge), $client, $verifier)->json();

        // Two hours on, the access key has expired (and may be pruned), but the refresh token is good for 60 days.
        $this->travel(2)->hours();
        $user->tokens()->delete();

        $this->actingAs($user);
        Volt::test('api-keys')->assertSee('Disconnect')->assertSee('Signed in')->assertDontSee('Claude (signed in)')
            ->call('disconnect', $client)->assertDontSee('Disconnect');
        $this->app['auth']->forgetGuards();

        $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_disconnect_also_spends_a_code_that_was_not_exchanged_yet(): void
    {
        $user = User::factory()->create();
        $client = $this->register();
        [$verifier, $challenge] = $this->pkce();
        $this->exchange($this->code($user, $client, $challenge), $client, $verifier)->assertOk();
        $pending = $this->code($user, $client, $challenge);

        $this->actingAs($user);
        Volt::test('api-keys')->call('disconnect', $client);
        $this->app['auth']->forgetGuards();

        $this->exchange($pending, $client, $verifier)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_disconnect_only_touches_your_own_connections(): void
    {
        $user = User::factory()->create();
        $client = $this->register();
        [$verifier, $challenge] = $this->pkce();
        $tokens = $this->exchange($this->code($user, $client, $challenge), $client, $verifier)->json();

        $this->actingAs(User::factory()->create());
        Volt::test('api-keys')->call('disconnect', $client);
        $this->app['auth']->forgetGuards();

        $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client])->assertOk();
    }

    public function test_an_api_key_still_works_on_mcp_and_rest(): void
    {
        $user = User::factory()->create();
        $key = ['Authorization' => 'Bearer '.$user->createToken('mine')->plainTextToken];

        $this->getJson('/api/v1/forms', $key)->assertOk();
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $key)->assertOk();
    }

    /**
     * The consent screen warns when the access would go to a host we do not know. The axis is the
     * redirect host: a known client, an unknown one under a trusted-sounding name, a look-alike of a
     * known host, a host added in config, and a loopback address (which has its own note).
     */
    public function test_an_unknown_redirect_host_gets_a_warning_that_names_it(): void
    {
        $user = User::factory()->create();
        [, $challenge] = $this->pkce();
        $screen = fn (string $clientId, string $redirect) => $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($this->authorizeQuery($clientId, $challenge, $redirect)))->assertOk();
        $warning = 'We do not know this app.';

        // The reported case: anyone registers as "Claude" with their own https redirect.
        $fake = $this->register(['https://evil.example/cb'], 'Claude');
        $screen($fake, 'https://evil.example/cb')
            ->assertSee($warning)
            ->assertSee('Any app can call itself "Claude". If you press Allow, <span class="font-mono break-all">evil.example</span> can read and change every form on this account and read all their messages.', false);

        // Close to a known host is not the known host.
        $lookalike = $this->register(['https://claude.ai.evil.example/cb', 'https://api.claude.ai/cb']);
        $screen($lookalike, 'https://claude.ai.evil.example/cb')->assertSee($warning);
        $screen($lookalike, 'https://api.claude.ai/cb')->assertSee($warning);

        // Control: Claude's real callback looks as before.
        $screen($this->register(), self::CLAUDE)->assertDontSee($warning)->assertSee('Connect Claude to FormHaven');

        // A loopback client keeps its own note and gets no second warning.
        $screen($this->register(['http://localhost:3118/callback'], 'Claude Code'), 'http://localhost:3118/callback')
            ->assertDontSee($warning)->assertSee('a program on this computer');

        // The list comes from config.
        config(['services.oauth.known_redirect_hosts' => ['claude.ai', 'evil.example']]);
        $screen($fake, 'https://evil.example/cb')->assertDontSee($warning);
    }

    public function test_unused_registrations_are_pruned_after_30_days(): void
    {
        $this->register();
        $this->travel(31)->days();
        $this->artisan('model:prune', ['--model' => [OAuthClient::class]])->assertSuccessful();
        $this->assertSame(0, OAuthClient::count());
        $this->assertSame(0, OAuthRefreshToken::count());
    }
}
