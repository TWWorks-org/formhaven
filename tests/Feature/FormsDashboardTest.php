<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class FormsDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_creates_a_form_and_sees_its_address(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('forms.index')
            ->set('name', 'Contact form on example.nl')
            ->call('createForm')
            ->assertHasNoErrors();

        $form = $user->forms()->sole();
        $this->get(route('forms.show', $form))
            ->assertOk()
            ->assertSee($form->endpoint());
    }

    public function test_submissions_show_and_spam_is_kept_apart(): void
    {
        $form = Form::factory()->create();
        $form->submissions()->create(['data' => ['message' => 'Real question']]);
        $form->submissions()->create(['data' => ['message' => 'Cheap pills'], 'spam' => true]);
        $this->actingAs($form->user);

        Volt::test('forms.show', ['form' => $form])
            ->assertSee('Real question')
            ->assertDontSee('Cheap pills')
            ->set('showSpam', true)
            ->assertSee('Cheap pills')
            ->assertDontSee('Real question');
    }

    public function test_search_finds_answers_in_any_field_including_accents_and_lists(): void
    {
        $form = Form::factory()->create();
        $form->submissions()->create(['data' => ['name' => 'Anna de Vries', 'message' => 'Offerte voor een café']]);
        $form->submissions()->create(['data' => ['name' => 'Bram', 'interests' => ['Logo', 'Webshop']]]);
        $form->submissions()->create(['data' => ['name' => 'Spam bot', 'message' => 'café deals'], 'spam' => true]);
        $form->submissions()->create(['data' => ['name' => 'Cees', 'message' => '100% done_now']]);
        $this->actingAs($form->user);

        $page = Volt::test('forms.show', ['form' => $form]);

        $page->set('search', 'café')->assertSee('Anna de Vries')->assertDontSee('Bram')->assertDontSee('Spam bot');
        $page->set('search', 'webshop')->assertSee('Bram')->assertDontSee('Anna de Vries');
        $page->set('search', 'VRIES')->assertSee('Anna de Vries');
        // Without the accent, and an accented capital, still find it.
        $page->set('search', 'cafe')->assertSee('Anna de Vries');
        $page->set('search', 'CAFÉ')->assertSee('Anna de Vries');
        // % and _ are searched as text, not as wildcards.
        $page->set('search', '%')->assertSee('Cees')->assertDontSee('Anna de Vries');
        $page->set('search', 'on_')->assertDontSee('Cees')->assertSee('Nothing matches &quot;on_&quot;.', false); // as a wildcard, _ would match "one"
        $page->set('search', 'interests')->assertSee('Nothing matches &quot;interests&quot;.', false); // field names are not answers
        $page->set('search', '')->assertSee('Anna de Vries')->assertSee('Bram')->assertSee('Cees');
    }

    public function test_export_is_a_csv_of_every_real_submission_with_a_column_per_field(): void
    {
        $form = Form::factory()->create(['name' => 'Quote request']);
        $old = $form->submissions()->create(['data' => ['name' => 'Anna', 'message' => "Two\nlines, \"quoted\""]]);
        $old->forceFill(['created_at' => now()->subDay()])->save();
        $form->submissions()->create(['data' => ['name' => 'Bram', 'phone' => '+31 6 12345678', 'interests' => ['Logo', 'Webshop']]]);
        $form->submissions()->create(['data' => ['name' => '=HYPERLINK("http://evil.example")']]);
        $form->submissions()->create(['data' => ['name' => 'Spam bot'], 'spam' => true]);
        $this->actingAs($form->user);

        $response = $this->get(route('forms.export', $form))->assertOk()->assertDownload('quote-request-submissions-'.now()->format('Y-m-d').'.csv');
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $rows = array_map('str_getcsv', preg_split('/\r?\n(?=(?:[^"]*"[^"]*")*[^"]*$)/', trim(substr($csv, 3))));
        $this->assertSame(['Received', 'name', 'message', 'phone', 'interests'], $rows[0]);
        $this->assertSame(['Anna', "Two\nlines, \"quoted\"", '', ''], array_slice($rows[1], 1));
        $this->assertSame(['Bram', '', "'+31 6 12345678", 'Logo, Webshop'], array_slice($rows[2], 1));
        $this->assertSame("'=HYPERLINK(\"http://evil.example\")", $rows[3][1]);
        $this->assertCount(4, $rows, 'spam is not exported');

        // Field names come from the visitor too, so the header row is guarded the same way.
        $form->submissions()->create(['data' => ['=cmd|calc' => 'x', '@SUM(1)' => 'y']]);
        $header = str_getcsv(strtok(substr($this->get(route('forms.export', $form))->streamedContent(), 3), "\n"));
        $this->assertSame(["'=cmd|calc", "'@SUM(1)"], array_slice($header, -2));
        $this->assertStringNotContainsString('Spam bot', $csv);
    }

    public function test_the_owner_turns_off_storing_and_sees_what_that_means(): void
    {
        $form = Form::factory()->create();
        $this->actingAs($form->user);

        Volt::test('forms.show', ['form' => $form])
            ->assertSee('Keep submissions in FormHaven')
            ->set('store_submissions', false)
            ->call('saveSettings')
            ->assertHasNoErrors();
        $this->assertFalse($form->fresh()->store_submissions);

        $form->submissions()->create(['data' => ['message' => 'Kept because unmailed']]);
        $form->submissions()->create(['data' => ['message' => 'gone']])->forget();

        Volt::test('forms.show', ['form' => $form->fresh()])
            ->assertSee('Kept because unmailed')
            ->assertSee('Emailed to you. The answers were not kept');

        $csv = $this->get(route('forms.export', $form))->streamedContent();
        $this->assertStringContainsString('Kept because unmailed', $csv);
        $this->assertSame(2, substr_count(trim($csv), "\n") + 1, 'header plus the one stored row');
    }

    public function test_nobody_exports_another_owners_form(): void
    {
        $form = Form::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->get(route('forms.export', $form))->assertNotFound();
    }

    public function test_settings_validate_and_save(): void
    {
        $form = Form::factory()->create();
        $this->actingAs($form->user);

        Volt::test('forms.show', ['form' => $form])
            ->set('redirect_url', 'not a url')
            ->set('retention_days', 0)
            ->call('saveSettings')
            ->assertHasErrors(['redirect_url', 'retention_days'])
            ->set('redirect_url', 'https://example.nl/bedankt')
            ->set('retention_days', 14)
            ->call('saveSettings')
            ->assertHasNoErrors();

        $this->assertSame('https://example.nl/bedankt', $form->fresh()->redirect_url);
        $this->assertSame(14, $form->fresh()->retention_days);
    }

    public function test_nobody_else_can_open_a_form(): void
    {
        $form = Form::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->get(route('forms.show', $form))->assertNotFound();
    }

    public function test_the_form_page_cannot_be_asked_for_someone_elses_submission(): void
    {
        $theirs = Form::factory()->create()->submissions()->create(['data' => ['name' => 'Other customer', 'message' => 'Private']]);
        $mine = Form::factory()->create();
        $this->actingAs($mine->user);

        $page = Volt::test('forms.show', ['form' => $mine]);

        // Every public method on the page is callable from the browser, with model ids bound for it
        // and its return value sent back, so no public method may take a submission id it does not scope.
        try {
            $page->call('summary', $theirs->id);
            $this->fail('summary() must not be callable from the browser');
        } catch (\Livewire\Exceptions\MethodNotFoundException) {
        }

        $page->call('selectSubmission', $theirs->id)->assertDontSee('Other customer');
        $page->call('deleteSubmission', $theirs->id);
        $this->assertNotNull($theirs->fresh(), 'another customer\'s submission is never deleted');
    }

    public function test_dashboard_sends_users_to_their_forms(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/dashboard')->assertRedirect('/forms');
    }

    public function test_the_keep_submissions_help_names_only_the_limits_this_install_has(): void
    {
        $form = Form::factory()->create();
        $this->actingAs($form->user);

        Volt::test('forms.show', ['form' => $form])->assertSee('daily or monthly email limit reached');

        // A self-hosted install has the daily mail limit (config/mail.php) and no monthly one.
        config(['formhaven.mode' => 'self_hosted']);
        Volt::test('forms.show', ['form' => $form])
            ->assertSee('unverified address, daily email limit reached')
            ->assertDontSee('monthly email limit');
    }
}
