<?php

namespace Tests\Feature;

use App\Mcp\Servers\FormHavenServer;
use App\Mcp\Tools\GetFormCodeTool;
use App\Mcp\Tools\GetPrivacyTextTool;
use App\Mcp\Tools\UpdateFormTool;
use App\Models\Form;
use App\Models\User;
use App\Support\WebhookTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Whatever the dashboard lets a person change, an agent can change too, under the same rules. */
class AgentParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WebhookTarget::$resolver = fn (string $host) => $host === 'hooks.example.com' ? ['93.184.216.34'] : [];
    }

    protected function tearDown(): void
    {
        WebhookTarget::$resolver = null;
        parent::tearDown();
    }

    private function key(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_patch_sets_every_dashboard_setting_and_returns_the_signing_secret_once(): void
    {
        $form = Form::factory()->create();
        $headers = $this->key($form->user);

        $response = $this->patchJson("/api/v1/forms/{$form->public_id}", [
            'name' => 'Quote form',
            'site_url' => 'https://client-site.nl',
            'retention_days' => 90,
            'store_submissions' => false,
            'webhook_url' => 'https://hooks.example.com/formhaven',
            'allowed_domains' => ['https://www.Client-Site.nl/contact', 'shop.nl'],
        ], $headers)->assertOk()
            ->assertJsonPath('data.name', 'Quote form')
            ->assertJsonPath('data.retention_days', 90)
            ->assertJsonPath('data.store_submissions', false)
            ->assertJsonPath('data.webhook_url', 'https://hooks.example.com/formhaven')
            ->assertJsonPath('data.allowed_domains', ['client-site.nl', 'shop.nl'])
            ->assertJsonMissingPath('data.webhook_secret');

        $secret = $form->fresh()->webhook_secret;
        $this->assertStringStartsWith('whsec_', $secret);
        $response->assertJsonPath('webhook_signing_secret', $secret);

        // Changing something else does not hand the secret out again.
        $this->patchJson("/api/v1/forms/{$form->public_id}", ['retention_days' => 7], $headers)
            ->assertOk()->assertJsonMissingPath('webhook_signing_secret');
        $this->assertSame($secret, $form->fresh()->webhook_secret);
    }

    public function test_one_bad_field_saves_nothing(): void
    {
        $form = Form::factory()->create();

        $this->patchJson("/api/v1/forms/{$form->public_id}", ['retention_days' => 90, 'allowed_domains' => ['not a domain!']], $this->key($form->user))
            ->assertUnprocessable()->assertJsonValidationErrors('allowed_domains');

        $this->assertSame(Form::DEFAULT_RETENTION_DAYS, $form->fresh()->retention_days);
    }

    public function test_a_private_webhook_is_refused_the_same_way_by_rest_mcp_and_the_dashboard(): void
    {
        $form = Form::factory()->create();
        $message = 'The webhook address points to a private or local network. Use a public https:// URL.';

        $this->patchJson("/api/v1/forms/{$form->public_id}", ['webhook_url' => 'https://127.0.0.1/hook'], $this->key($form->user))
            ->assertUnprocessable()->assertJsonPath('errors.webhook_url.0', $message);

        FormHavenServer::actingAs($form->user)->tool(UpdateFormTool::class, ['form_id' => $form->public_id, 'webhook_url' => 'https://127.0.0.1/hook'])
            ->assertHasErrors([$message]);

        $this->actingAs($form->user);
        Volt::test('forms.show', ['form' => $form])->set('webhook_url', 'https://127.0.0.1/hook')->call('saveSettings')
            ->assertHasErrors('webhook_url');

        $this->assertNull($form->fresh()->webhook_url);
    }

    public function test_a_malformed_didmyformsend_key_is_refused_the_same_way_by_rest_mcp_and_the_dashboard(): void
    {
        $form = Form::factory()->create();
        $message = \App\Support\DidMyFormSend::KEY_MESSAGE;

        $this->patchJson("/api/v1/forms/{$form->public_id}", ['monitor_key' => 'fmh_123'], $this->key($form->user))
            ->assertUnprocessable()->assertJsonPath('errors.monitor_key.0', $message);

        FormHavenServer::actingAs($form->user)->tool(UpdateFormTool::class, ['form_id' => $form->public_id, 'monitor_key' => 'fmh_123'])
            ->assertHasErrors([$message]);

        $this->actingAs($form->user);
        Volt::test('forms.show', ['form' => $form])->set('monitor_key', 'fmh_123')->call('saveSettings')
            ->assertHasErrors(['monitor_key']);

        $this->assertNull($form->fresh()->monitor_key);
    }

    public function test_mcp_update_form_changes_settings_and_cannot_reach_another_account(): void
    {
        $form = Form::factory()->create();

        FormHavenServer::actingAs($form->user)->tool(UpdateFormTool::class, ['form_id' => $form->public_id, 'retention_days' => 3, 'allowed_domains' => ['example.nl']])
            ->assertOk()->assertSee('"retention_days":3');
        $this->assertSame(['example.nl'], $form->fresh()->allowed_domains);

        FormHavenServer::actingAs(User::factory()->create())->tool(UpdateFormTool::class, ['form_id' => $form->public_id, 'retention_days' => 1])
            ->assertHasErrors(['No form with this id on the account. Call list_forms to see the ids.']);
        $this->assertSame(3, $form->fresh()->retention_days);
    }

    public function test_get_form_code_returns_each_stack_with_the_real_address(): void
    {
        $form = Form::factory()->create(['bot_check' => true]);
        $endpoint = $form->endpoint();

        FormHavenServer::actingAs($form->user)->tool(GetFormCodeTool::class, ['form_id' => $form->public_id])
            ->assertOk()->assertSee(['"html"', '"js"', '"react"', '"astro"', $endpoint, 'bot-check.js'])->assertDontSee('"ai"');

        FormHavenServer::actingAs($form->user)->tool(GetFormCodeTool::class, ['form_id' => $form->public_id, 'stack' => 'react'])
            ->assertOk()->assertSee(['useState', $endpoint])->assertDontSee('"astro"');

        FormHavenServer::actingAs($form->user)->tool(GetFormCodeTool::class, ['form_id' => $form->public_id, 'stack' => 'vue'])
            ->assertHasErrors(['stack must be one of: html, js, react, astro. Leave it out to get all of them.']);
    }

    public function test_the_privacy_text_follows_the_form_settings_in_each_language(): void
    {
        $form = Form::factory()->create(['retention_days' => 30]);
        $url = "/api/v1/forms/{$form->public_id}/privacy-text";
        $headers = $this->key($form->user);

        $en = $this->getJson($url, $headers)->assertOk()->json('data');
        $this->assertStringContainsString('FormHaven deletes its copy after 30 days.', $en['paragraph']);
        $this->assertStringContainsString(config('legal.entity').' in the Netherlands', $en['paragraph']);
        $this->assertStringContainsString('Hetzner Online GmbH in Falkenstein, Germany', $en['paragraph']);
        $this->assertStringContainsString('Cloudflare, Inc.', $en['paragraph']);
        $this->assertSame(30, $en['facts']['formhaven_keeps_copy_days']);

        $form->update(['retention_days' => 1]);
        $this->assertStringContainsString('after 1 day.', $this->getJson($url, $headers)->json('data.paragraph'));

        $form->update(['store_submissions' => false]);
        $none = $this->getJson($url, $headers)->json('data');
        $this->assertStringContainsString('deletes your answers as soon as the email to us has been sent', $none['paragraph']);
        $this->assertStringNotContainsString('deletes its copy after', $none['paragraph']);

        $this->assertStringContainsString('in Nederland', $this->getJson("{$url}?lang=nl", $headers)->json('data.paragraph'));
        $de = $this->getJson("{$url}?lang=de", $headers)->json('data');
        $this->assertStringContainsString('in den Niederlanden', $de['paragraph']);
        $this->assertSame('Kontaktformular', $de['heading']);

        $this->getJson("{$url}?lang=fr", $headers)->assertUnprocessable()->assertJsonPath('message', 'lang must be one of: en, nl, de.');
        // The guard remembers the last user within one test; forget it so the other key is read.
        $this->app['auth']->forgetGuards();
        $this->getJson($url, $this->key(User::factory()->create()))->assertNotFound();
    }

    public function test_mcp_get_privacy_text(): void
    {
        $form = Form::factory()->create(['retention_days' => 14]);

        FormHavenServer::actingAs($form->user)->tool(GetPrivacyTextTool::class, ['form_id' => $form->public_id, 'language' => 'nl'])
            ->assertOk()->assertSee(['Contactformulier', 'na 14 dagen']);
        FormHavenServer::actingAs($form->user)->tool(GetPrivacyTextTool::class, ['form_id' => $form->public_id, 'language' => 'fr'])
            ->assertHasErrors(['language must be one of: en, nl, de.']);
    }

    public function test_the_dashboard_shows_the_privacy_text_and_the_react_tab(): void
    {
        $form = Form::factory()->create(['retention_days' => 45]);
        $this->actingAs($form->user);

        Volt::test('forms.show', ['form' => $form])
            ->assertSee('Privacy text')
            ->assertSee('FormHaven deletes its copy after 45 days.')
            ->assertSee('Kontaktformular')
            ->assertSee('useState');
    }

    public function test_the_legal_pages_name_the_hosting_place_the_privacy_text_uses(): void
    {
        foreach (['/legal/dpa', '/legal/subprocessors'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString(explode(' ', config('legal.hosting.provider'))[0], $html, $path);
            $this->assertStringContainsString(explode(',', config('legal.hosting.place.en'))[0], $html, $path);
        }
        $this->assertStringContainsString(config('legal.mailer'), $this->get('/legal/subprocessors')->getContent());
    }
}
