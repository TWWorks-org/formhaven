<?php

namespace Tests\Feature;

use App\Mail\SubmissionReceived;
use App\Models\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AllowedDomainsTest extends TestCase
{
    use RefreshDatabase;

    /** Posts like a browser on $origin would, and returns whether it landed in the inbox. */
    private function lands(Form $form, array $headers): bool
    {
        $this->postJson("/f/{$form->public_id}", ['message' => 'Hi'], $headers)->assertOk()->assertJson(['ok' => true]);

        return ! $form->submissions()->latest('id')->first()->spam;
    }

    public function test_a_form_without_a_list_takes_posts_from_any_site(): void
    {
        Mail::fake();
        $form = Form::factory()->create();

        $this->assertTrue($this->lands($form, ['Origin' => 'https://anywhere.example']));
        $this->assertTrue($this->lands($form, []));
        Mail::assertQueued(SubmissionReceived::class, 2);
    }

    public function test_a_listed_domain_covers_itself_www_and_subdomains(): void
    {
        Mail::fake();
        $form = Form::factory()->create(['allowed_domains' => ['example.nl']]);

        $this->assertTrue($this->lands($form, ['Origin' => 'https://example.nl']));
        $this->assertTrue($this->lands($form, ['Origin' => 'https://www.example.nl']));
        $this->assertTrue($this->lands($form, ['Origin' => 'https://shop.EXAMPLE.nl:8443']));
        // A plain HTML post from an older browser may carry only the Referer.
        $this->assertTrue($this->lands($form, ['Referer' => 'https://example.nl/contact?x=1']));
        // A sandboxed frame sends Origin "null"; the Referer decides then.
        $this->assertTrue($this->lands($form, ['Origin' => 'null', 'Referer' => 'https://example.nl/']));
    }

    public function test_lookalike_hosts_go_to_spam_and_are_not_mailed(): void
    {
        Mail::fake();
        $form = Form::factory()->create(['allowed_domains' => ['example.nl']]);

        foreach (['https://notexample.nl', 'https://example.nl.evil.com', 'https://evil.com', 'https://example.com'] as $origin) {
            $this->assertFalse($this->lands($form, ['Origin' => $origin]), $origin);
        }

        // The Origin wins over a Referer that names the allowed site.
        $this->assertFalse($this->lands($form, ['Origin' => 'https://evil.com', 'Referer' => 'https://example.nl/']));
        Mail::assertNothingQueued();
        $this->assertSame('not_allowed:evil.com', $form->submissions()->latest('id')->first()->spam_reason);
    }

    public function test_a_listed_subdomain_does_not_cover_its_parent(): void
    {
        Mail::fake();
        $form = Form::factory()->create(['allowed_domains' => ['blog.example.com']]);

        $this->assertTrue($this->lands($form, ['Origin' => 'https://blog.example.com']));
        $this->assertFalse($this->lands($form, ['Origin' => 'https://example.com']));
        $this->assertFalse($this->lands($form, ['Origin' => 'https://shop.example.com']));
    }

    public function test_localhost_always_passes_so_owners_can_test_before_deploying(): void
    {
        Mail::fake();
        $form = Form::factory()->create(['allowed_domains' => ['example.nl']]);

        $this->assertTrue($this->lands($form, ['Origin' => 'http://localhost:3000']));
        $this->assertTrue($this->lands($form, ['Origin' => 'http://127.0.0.1:8000']));
        $this->assertTrue($this->lands($form, ['Origin' => 'http://[::1]:5173']));
        $this->assertTrue($this->lands($form, ['Origin' => 'http://mysite.localhost']));
    }

    public function test_a_post_naming_no_site_goes_to_spam_with_a_reason(): void
    {
        Mail::fake();
        $form = Form::factory()->create(['allowed_domains' => ['example.nl']]);

        $this->assertFalse($this->lands($form, []));
        $submission = $form->submissions()->sole();
        $this->assertSame('no_origin', $submission->spam_reason);
        $this->assertStringContainsString('did not say which site', $submission->spamReason());
    }

    /** Since 2026-11-24 a bad address gets the same 422 from a refused site as from a listed one, so the answer tells nothing about the list. */
    public function test_a_refused_site_learns_nothing_even_with_a_bad_email(): void
    {
        Mail::fake();
        $form = Form::factory()->create(['allowed_domains' => ['example.nl']]);

        $listed = $this->postJson("/f/{$form->public_id}", ['email' => 'nope'], ['Origin' => 'https://example.nl'])->assertStatus(422);
        $refused = $this->postJson("/f/{$form->public_id}", ['email' => 'nope'], ['Origin' => 'https://evil.com'])->assertStatus(422);
        $this->assertSame($listed->json(), $refused->json());
        $this->assertSame(0, $form->submissions()->count());
    }

    public function test_a_form_that_stores_nothing_still_keeps_a_refused_site_message_but_not_honeypot_spam(): void
    {
        Mail::fake();
        $form = Form::factory()->create(['allowed_domains' => ['example.nl'], 'store_submissions' => false]);

        $this->postJson("/f/{$form->public_id}", ['message' => 'Real person on a new domain'], ['Origin' => 'https://new-site.nl'])->assertOk();
        $this->postJson("/f/{$form->public_id}", ['message' => 'Bot', '_gotcha' => 'x'], ['Origin' => 'https://example.nl'])->assertOk();

        [$refused, $bot] = $form->submissions()->orderBy('id')->get();
        $this->assertSame(['message' => 'Real person on a new domain'], $refused->data);
        $this->assertSame([], $bot->data);
        $this->assertSame('honeypot', $bot->spam_reason);
    }

    public function test_the_owner_sees_why_a_message_is_in_spam(): void
    {
        Mail::fake();
        $form = Form::factory()->create(['allowed_domains' => ['example.nl']]);
        $this->postJson("/f/{$form->public_id}", ['name' => 'Anna', 'message' => 'Hi'], ['Origin' => 'https://staging.other.nl'])->assertOk();
        $this->actingAs($form->user);

        Volt::test('forms.show', ['form' => $form])
            ->set('showSpam', true)
            ->assertSee('Marked as spam')
            ->assertSee('Sent from staging.other.nl, which is not one of this form');

        $this->getJson("/api/v1/forms/{$form->public_id}/submissions?spam=1", $this->apiHeaders($form))
            ->assertJsonPath('data.0.spam_reason', "Sent from staging.other.nl, which is not one of this form's allowed sites. If that site is yours, add it under Settings.");
    }

    public function test_the_dashboard_test_button_is_not_held_to_the_list(): void
    {
        Mail::fake();
        $form = Form::factory()->create(['allowed_domains' => ['example.nl']]);
        $form->user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($form->user);

        Volt::test('forms.show', ['form' => $form])->call('sendTest');

        $this->assertFalse($form->submissions()->sole()->spam);
        Mail::assertQueued(SubmissionReceived::class);
    }

    public function test_settings_store_what_the_owner_typed_as_plain_domains(): void
    {
        $form = Form::factory()->create();
        $this->actingAs($form->user);

        Volt::test('forms.show', ['form' => $form])
            ->set('allowed_domains', "https://www.Example.nl/contact\n*.shop.nl, example.nl\n\n")
            ->call('saveSettings')
            ->assertHasNoErrors()
            ->assertSet('allowed_domains', "example.nl\nshop.nl");

        $this->assertSame(['example.nl', 'shop.nl'], $form->fresh()->allowed_domains);
        $this->assertSame(['example.nl', 'shop.nl'], $form->fresh()->toApi()['allowed_domains']);
    }

    public function test_settings_refuse_what_is_not_a_domain_and_clearing_opens_the_form(): void
    {
        $form = Form::factory()->create(['allowed_domains' => ['example.nl']]);
        $this->actingAs($form->user);

        foreach (['not a domain', 'localhost', 'exa_mple.nl', 'https://'] as $typed) {
            Volt::test('forms.show', ['form' => $form])->set('allowed_domains', $typed)->call('saveSettings')->assertHasErrors('allowed_domains');
        }
        $this->assertSame(['example.nl'], $form->fresh()->allowed_domains);

        Volt::test('forms.show', ['form' => $form])
            ->set('allowed_domains', implode("\n", array_map(fn ($i) => "site{$i}.nl", range(1, Form::MAX_ALLOWED_DOMAINS + 1))))
            ->call('saveSettings')->assertHasErrors('allowed_domains');

        Volt::test('forms.show', ['form' => $form])->set('allowed_domains', '  ')->call('saveSettings')->assertHasNoErrors();
        $this->assertNull($form->fresh()->allowed_domains);
        $this->assertSame([], $form->fresh()->toApi()['allowed_domains']);
    }

    public function test_the_add_my_site_shortcut_encodes_the_host_for_javascript(): void
    {
        // Saved straight to the row, past validation, to prove the template is safe on its own.
        $form = Form::factory()->create();
        $form->forceFill(['site_url' => "https://x');alert(1);//.nl"])->save();
        $this->actingAs($form->user);

        $html = Volt::test('forms.show', ['form' => $form])->html();

        preg_match('/x-on:click="\$wire\.allowed_domains = ([^"]*)"/', $html, $m);
        $this->assertNotEmpty($m, 'The shortcut button should render');
        // Decoded the way the browser does before Alpine runs it: one JS string literal, nothing after it.
        $this->assertMatchesRegularExpression("/^'(?:[^'\\\\]|\\\\.)*'$/", html_entity_decode($m[1], ENT_QUOTES));
    }

    private function apiHeaders(Form $form): array
    {
        return ['Authorization' => 'Bearer '.$form->user->createToken('test')->plainTextToken];
    }
}
