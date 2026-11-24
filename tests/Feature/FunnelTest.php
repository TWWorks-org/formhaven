<?php

namespace Tests\Feature;

use App\Mail\SubmissionReceived;
use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** The landing demo, the dashboard test button and the pages a site's visitors see. */
class FunnelTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_reports_each_step_and_stores_and_emails_nothing(): void
    {
        Mail::fake();

        $this->postJson('/demo', ['email' => 'anna@example.net', 'message' => 'Hoi'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('steps.0.text', '2 fields received: email, message')
            ->assertJsonPath('steps.1.text', 'Spam trap empty, under the rate limit: passed')
            ->assertJsonPath('steps.3.text', 'A real form emails it to the owner. Pressing Reply goes to anna@example.net.');

        $this->postJson('/demo', ['message' => 'buy now', '_gotcha' => 'x'])
            ->assertJsonPath('steps.1.text', 'Spam trap filled in: kept in the spam list, not emailed')
            ->assertJsonMissingPath('steps.4');

        $this->postJson('/demo', ['email' => '', 'message' => ''])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Type something in at least one field first.');

        $this->assertDatabaseCount('submissions', 0);
        Mail::assertNothingQueued();
    }

    public function test_the_landing_page_carries_the_demo_form_and_the_data_location_table(): void
    {
        $this->get('/')
            ->assertSee('id="demo-form"', false)
            ->assertSee('action="'.route('demo').'"', false)
            ->assertSee('Where the submissions live')
            ->assertSee('https://formspree.io/legal/privacy-policy', false);
    }

    public function test_the_test_button_runs_the_real_path_and_emails_the_owner(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        Volt::actingAs($user)->test('forms.show', ['form' => $form])
            ->assertSee('Waiting for the first message.')
            ->assertSee($form->endpoint())
            ->call('sendTest')
            ->assertSee('Test sent. It is below, and an email is on its way to '.$user->email.'.')
            ->assertSee('This is a test sent from the dashboard')
            ->assertSee('Now try it from your site.');

        $this->assertSame(1, $form->submissions()->where('spam', false)->count());
        Mail::assertQueued(SubmissionReceived::class, 1);
    }

    public function test_the_test_button_is_limited_to_three_an_hour(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $page = Volt::actingAs($user)->test('forms.show', ['form' => $form]);

        foreach (range(1, 3) as $i) {
            $page->call('sendTest');
        }
        $page->call('sendTest')->assertSee('You sent 3 tests in the last hour.');

        $this->assertSame(3, $form->submissions()->count());
    }

    public function test_the_test_button_says_why_when_the_form_is_paused(): void
    {
        $user = User::factory()->create(['trial_ends_at' => now()->subDay()]);
        $form = Form::factory()->for($user)->create();

        Volt::actingAs($user)->test('forms.show', ['form' => $form])
            ->call('sendTest')
            ->assertSee('This form is paused because the site owner');

        $this->assertSame(0, $form->submissions()->count());
    }

    public function test_every_stack_tab_carries_the_real_form_address(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $html = $this->actingAs($user)->get(route('forms.show', $form))->assertOk()->getContent();

        foreach (['HTML', 'JavaScript', 'Astro', 'Prompt for your AI'] as $tab) {
            $this->assertStringContainsString('>'.$tab.'</button>', $html);
        }
        // Once in the address bar and once in each of the four snippets, plus the copy buttons.
        $this->assertGreaterThanOrEqual(5, substr_count($html, $form->endpoint()));
    }

    public function test_the_thank_you_page_links_back_to_the_site_the_owner_saved(): void
    {
        $form = Form::factory()->for(User::factory()->create())->create(['site_url' => 'https://bakkerij-jansen.nl/contact']);

        $response = $this->post("/f/{$form->public_id}", ['message' => 'Hi'], ['Referer' => 'https://bakkerij-jansen.nl/contact']);
        $this->assertStringContainsString('signature=', $response->headers->get('Location'));
        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('Back to bakkerij-jansen.nl')
            ->assertSee('href="https://bakkerij-jansen.nl"', false)
            ->assertSee('Stored in the EU by');
    }

    public function test_a_forged_referer_never_reaches_the_back_link(): void
    {
        $saved = Form::factory()->for(User::factory()->create())->create(['site_url' => 'https://studio-noord.nl']);
        $unsaved = Form::factory()->for(User::factory()->create())->create();

        // A scripted post can claim any Referer: the link still goes to the saved site, or nowhere.
        foreach ([$saved, $unsaved] as $form) {
            $location = $this->post("/f/{$form->public_id}", ['message' => 'Hi'], ['Referer' => 'https://evil.example/'])
                ->headers->get('Location');
            $this->assertStringNotContainsString('evil', $location);
            $this->get($location)->assertOk()->assertDontSee('evil.example');
        }

        $this->get(route('submit.thanks', ['form' => $unsaved->public_id]))->assertSee('The site owner has it')->assertDontSee('Back to');
    }

    public function test_only_a_fresh_signed_link_from_a_submission_shows_the_back_button(): void
    {
        // Anyone can save any site on a trial form; a bare or stale link must not put it on our domain.
        $form = Form::factory()->for(User::factory()->create())->create(['site_url' => 'https://phishing.example']);

        $this->get(route('submit.thanks', ['form' => $form->public_id]))
            ->assertOk()->assertDontSee('phishing.example')->assertSee('The site owner has it');

        $location = $this->post("/f/{$form->public_id}", ['message' => 'Hi'])->headers->get('Location');
        $this->get($location)->assertSee('Back to phishing.example');

        $this->travel(11)->minutes();
        $this->get($location)->assertOk()->assertDontSee('phishing.example');
    }

    public function test_nothing_in_the_url_can_choose_the_back_link(): void
    {
        $this->get('/f/thanks?back='.urlencode('https://evil.example'))
            ->assertOk()->assertDontSee('evil.example')->assertSee('The site owner has it');
        $this->get('/f/thanks?form=does-not-exist&back='.urlencode('https://evil.example'))
            ->assertOk()->assertDontSee('evil.example');
    }

    public function test_the_error_page_links_back_to_the_saved_site_not_the_referer(): void
    {
        $form = Form::factory()->for(User::factory()->create(['trial_ends_at' => now()->subDay()]))
            ->create(['site_url' => 'https://studio-noord.nl']);

        $this->post("/f/{$form->public_id}", ['message' => 'Hi'], ['Referer' => 'https://evil.example/'])
            ->assertStatus(402)
            ->assertSee('Your message was not sent')
            ->assertSee('Back to studio-noord.nl')
            ->assertDontSee('evil.example');
    }

    public function test_the_website_is_saved_from_the_dashboard_the_api_and_mcp_rules(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)->test('forms.index')
            ->set('name', 'Contact form on yoga-delft.nl')
            ->set('site_url', 'https://yoga-delft.nl')
            ->call('createForm');
        $form = $user->forms()->first();
        $this->assertSame('https://yoga-delft.nl', $form->site_url);
        $this->assertSame('https://yoga-delft.nl', $form->toApi()['site_url']);

        Volt::actingAs($user)->test('forms.show', ['form' => $form])
            ->set('site_url', 'not a url')->call('saveSettings')->assertHasErrors('site_url')
            ->set('site_url', 'https://www.yoga-delft.nl')->call('saveSettings')->assertHasNoErrors();
        $this->assertSame('https://www.yoga-delft.nl', $form->fresh()->site_url);

        $this->assertArrayHasKey('site_url', \App\Actions\CreateForm::RULES);
    }

    public function test_a_template_page_builds_its_code_from_its_own_fields(): void
    {
        $html = $this->get('/templates/withdrawal-form')->assertOk()
            ->assertSee('EU withdrawal form for your web shop, in HTML')
            ->assertSee('Article 6(1)(h)')
            ->assertSee(route('templates.show', 'contact-form'), false)
            ->getContent();

        foreach (['goods_or_service', 'ordered_on', 'received_on', 'name', 'address', 'email', '_gotcha'] as $field) {
            $this->assertStringContainsString('name=&quot;'.$field.'&quot;', $html, "code is missing {$field}");
        }
        $this->assertStringContainsString(e(url('/f/YOUR_FORM_ID')), $html);

        $this->get('/templates/contact-form')->assertOk()->assertDontSee('goods_or_service');
        $this->get('/templates/nope')->assertNotFound();
    }

    public function test_the_comparison_page_quotes_our_price_from_config_and_cites_its_sources(): void
    {
        // An extra 1,000 never costs more than a form, so the cheapest bill is the minimum forms plus extra thousands.
        // Within the minimum's own submissions the cell gives month and year; past it, the month and what the rest costs.
        $this->get('/alternatives/formspree')->assertOk()
            ->assertSee('€7.50 a month on up to 5 forms (5,000 submissions included, the next 5,000 at €0.50 per 1,000)')
            ->assertSee('€5 a month, or €50 a year (5,000 submissions a month, on up to 5 forms)')
            ->assertDontSee('€0.5 ')
            ->assertSee('$90 a month (Business, up to 20,000)')
            ->assertSee('Stay with Formspree if')
            ->assertSee('https://formspree.io/plans', false)
            ->assertSee('27 October 2026');

        // The cell and the running text name the same bill.
        $this->get('/alternatives/basin')->assertOk()
            ->assertSee('€15 a month on up to 5 forms (5,000 submissions included, the next 20,000 at €0.50 per 1,000)')
            ->assertSee('€5 a month, or €50 a year (5,000 submissions a month, on up to 5 forms)')
            ->assertSee('You need about 5,000 submissions a month: €5 a month against $40.83');
        $this->get('/alternatives/formcarry')->assertOk()
            ->assertSee('€7.50 a month on up to 5 forms')
            ->assertSee('You need about 10,000 submissions a month: €7.50 a month against $99');

        config(['billing.per_form.monthly_eur' => 2, 'billing.per_form.yearly_eur' => 20, 'billing.per_form.extra_eur' => 2]);
        $this->get('/alternatives/formspree')->assertSee('€20 a month on up to 5 forms (5,000 submissions included, the next 5,000 at €2 per 1,000)');
        $this->get('/alternatives/formcarry')->assertSee('You need about 10,000 submissions a month: €20 a month against $99');

        $this->get('/alternatives/nope')->assertNotFound();
    }

    public function test_pricing_shows_the_switch_with_yearly_selected(): void
    {
        $p = config('billing.per_form');

        // The home page's price teaser shows each example bill monthly and yearly side by side, with no switch.
        // On /pricing the switch is the calculator's two buttons; the price list shows both rates side by side.
        $html = $this->get('/pricing')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-period="yearly" aria-pressed="true"/', $html, '/pricing does not default to yearly');
        $this->assertMatchesRegularExpression('/data-period="monthly" aria-pressed="false"/', $html);
        $this->assertMatchesRegularExpression('#<span id="price-unit"[^>]*>a year for#', $html, 'the calculator does not show a yearly bill by default');
        $this->assertStringContainsString('€'.$p['yearly_eur'].' per form a year', $html);
        $this->assertStringContainsString('€'.$p['monthly_eur'].' per form a month', $html);
    }

    public function test_the_sitemap_lists_every_public_page(): void
    {
        $this->artisan('sitemap:generate')->assertSuccessful();
        $xml = file_get_contents(public_path('sitemap.xml'));
        unlink(public_path('sitemap.xml')); // deploy generates the real one

        foreach (['/pricing', '/legal/privacy', '/legal/terms', '/legal/dpa', '/legal/subprocessors', '/templates', '/templates/withdrawal-form', '/templates/contact-form', '/alternatives/formspree'] as $path) {
            $this->assertStringContainsString(url($path).'</loc>', $xml);
        }
    }
}
