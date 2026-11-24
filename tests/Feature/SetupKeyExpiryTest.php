<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Setup keys from POST /api/v1/start expire after config sanctum.setup_key_days. The axis is the
 * key's age against that limit and the surface it is used on (REST, MCP); the controls are keys
 * that must not change: one made before expiry existed, a dashboard key, and a wrong key.
 */
class SetupKeyExpiryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: array, 1: User, 2: array} the start answer, the owner and the auth header */
    private function activated(): array
    {
        $started = $this->postJson('/api/v1/start', ['name' => 'Contact form on example.nl'])->assertCreated()->json();
        $owner = User::factory()->create();
        $this->actingAs($owner)->post($started['data']['claim_url'])->assertRedirect();
        $this->app['auth']->forgetGuards();

        return [$started, $owner, ['Authorization' => 'Bearer '.$started['data']['setup_key']]];
    }

    private function mcp(array $headers)
    {
        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_forms', 'arguments' => []]], $headers);
    }

    public function test_a_setup_key_works_until_its_last_day_then_says_it_expired_and_what_to_do(): void
    {
        $this->travelTo('2026-11-24 10:00:00');
        [$started, , $key] = $this->activated();

        $this->assertStringContainsString('The key works for 30 days after activation', $started['next']);
        $this->getJson('/api/v1/start/'.$started['data']['id'], $key)->assertJsonPath('data.setup_key_expires_at', '2026-12-24T10:00:00+00:00');

        $this->travelTo('2026-12-24 09:59:00');
        $this->getJson('/api/v1/forms', $key)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->mcp($key)->assertOk()->assertSee($started['data']['id']);
        $this->app['auth']->forgetGuards();

        $this->travelTo('2026-12-24 10:01:00');
        $expected = [
            'message' => 'This setup key expired on 2026-12-24. A setup key works for 30 days after the site owner activates the form. To go on, ask the site owner to create an API key on the API keys page ('.route('api-keys').') and send that as "Authorization: Bearer <key>" on the REST API and the MCP server.',
            'code' => 'SETUP_KEY_EXPIRED',
            'api_keys' => route('api-keys'),
            'docs' => url('/llms.txt'),
        ];
        $this->getJson('/api/v1/forms', $key)->assertUnauthorized()->assertExactJson($expected);
        $this->app['auth']->forgetGuards();
        $this->mcp($key)->assertUnauthorized()->assertExactJson($expected);
        $this->app['auth']->forgetGuards();
        $this->patchJson('/api/v1/forms/'.$started['data']['id'], ['name' => 'Taken over'], $key)->assertUnauthorized()->assertJsonPath('code', 'SETUP_KEY_EXPIRED');
    }

    public function test_the_number_of_days_comes_from_config(): void
    {
        config(['sanctum.setup_key_days' => 7]);
        $this->travelTo('2026-11-24 10:00:00');
        [$started, , $key] = $this->activated();

        $this->assertStringContainsString('The key works for 7 days after activation', $started['next']);
        $this->travelTo('2026-12-01 10:01:00');
        $this->getJson('/api/v1/forms', $key)->assertUnauthorized()->assertJsonPath('message', fn ($m) => str_contains($m, 'works for 7 days'));
        $this->get('/llms.txt')->assertSee('It works for 7 days after activation');
    }

    public function test_control_keys_without_an_expiry_a_dashboard_key_and_a_wrong_key_are_unchanged(): void
    {
        [$started, $owner, $key] = $this->activated();
        // A key made before setup keys expired: the database row has no expires_at, and keeps none.
        PersonalAccessToken::where('name', 'like', 'Agent setup:%')->update(['expires_at' => null]);
        $dashboardKey = ['Authorization' => 'Bearer '.$owner->createToken('mine')->plainTextToken];

        $this->travel(400)->days();
        $this->getJson('/api/v1/forms', $key)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/forms', $dashboardKey)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/forms', ['Authorization' => 'Bearer fhs_wrong'])->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $this->assertNull(PersonalAccessToken::where('name', 'like', 'Agent setup:%')->value('expires_at'));
    }

    public function test_an_expired_oauth_key_keeps_the_plain_answer(): void
    {
        $owner = User::factory()->create();
        $token = $owner->createToken('Claude (signed in)', ['mcp:use'], now()->subMinute())->plainTextToken;

        $this->mcp(['Authorization' => 'Bearer '.$token])->assertUnauthorized()->assertJsonMissingPath('code');
    }

    public function test_the_form_page_lists_its_setup_key_and_revoke_ends_it(): void
    {
        $this->travelTo('2026-11-24 10:00:00');
        [$started, $owner, $key] = $this->activated();
        $form = Form::where('public_id', $started['data']['id'])->sole();
        $other = Form::factory()->for($owner)->create();

        $page = Volt::actingAs($owner)->test('forms.show', ['form' => $form])
            ->assertSee('Agent setup keys')
            ->assertSee(substr($started['data']['setup_key'], 0, 8).'…')
            ->assertSee('Created Nov 24, 2026')
            ->assertSee('expires Dec 24, 2026')
            ->assertSee('works for 30 days after you activated it');
        // Another form of the same owner shows no key that is not its own.
        Volt::actingAs($owner)->test('forms.show', ['form' => $other])->assertDontSee('Agent setup keys');

        // Revoke on another form's page cannot reach this form's key.
        Volt::actingAs($owner)->test('forms.show', ['form' => $other])->call('revokeSetupKey', PersonalAccessToken::sole()->id);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $page->call('revokeSetupKey', PersonalAccessToken::sole()->id);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/forms', $key)->assertUnauthorized();
    }
}
