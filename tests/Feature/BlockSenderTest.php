<?php

namespace Tests\Feature;

use App\Actions\FormSpamSettings;
use App\Mail\SubmissionReceived;
use App\Models\Form;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * "Block this sender": the sales pitches that reach a real inbox are typed on the real page with
 * a new name each time but from the same company domain, so no bot rule catches them.
 */
class BlockSenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function receive(Form $form, array $fields): Submission
    {
        $this->postJson("/f/{$form->public_id}", $fields)->assertOk()->assertJson(['ok' => true]);

        return $form->submissions()->latest('id')->first();
    }

    public function test_blocking_a_company_sender_catches_the_next_pitch_under_a_new_name_and_nobody_else(): void
    {
        $form = Form::factory()->create();
        $first = $this->receive($form, ['name' => 'Test Sender One', 'email' => 'hello@leadgen.example', 'message' => 'How fast would I get a call back?']);
        $this->assertFalse($first->spam);

        $this->post($first->blockUrl())->assertOk()->assertSee('@leadgen.example');

        $this->assertSame('marked', $first->fresh()->spam_reason);
        $this->assertSame(['@leadgen.example'], $form->fresh()->spam_words);

        // The same company with the next made-up name: spam, and the owner is told why in words.
        $next = $this->receive($form, ['name' => 'Test Sender Two', 'email' => 'Hello@LeadGen.Example', 'message' => 'Reaching out through your contact form']);
        $this->assertTrue($next->spam);
        $this->assertSame('It came from @leadgen.example, a sender you blocked on this form.', $next->spamReason());

        // Controls: another company, and a domain that only ends the same way, still arrive.
        $this->assertFalse($this->receive($form, ['email' => 'jan@bakery.example.org', 'message' => 'Can you quote a cake?'])->spam);
        $this->assertFalse($this->receive($form, ['email' => 'ann@notleadgen.example.org', 'message' => 'Hi'])->spam);
    }

    public function test_at_a_shared_mail_provider_only_the_one_address_is_blocked(): void
    {
        $form = Form::factory()->create();
        $junk = $this->receive($form, ['name' => 'TestBot', 'email' => 'test-sender-0001@gmx.com', 'message' => 'fejwlkdwkjrerwkrwirej']);

        $this->post($junk->blockUrl())->assertOk();

        $this->assertSame(['test-sender-0001@gmx.com'], $form->fresh()->spam_words);
        $this->assertTrue($this->receive($form, ['email' => 'test-sender-0001@gmx.com', 'message' => 'again'])->spam);
        $this->assertFalse($this->receive($form, ['email' => 'real.customer@gmx.com', 'message' => 'A real question'])->spam);

        $gmail = $this->receive($form, ['email' => 'someone@gmail.com', 'message' => 'pitch']);
        $this->post($gmail->blockUrl())->assertOk();
        $this->assertNotContains('@gmail.com', $form->fresh()->spam_words);
        $this->assertFalse($this->receive($form, ['email' => 'another@gmail.com', 'message' => 'Real lead'])->spam);
    }

    public function test_opening_the_link_changes_nothing_until_the_button_is_pressed(): void
    {
        $form = Form::factory()->create();
        $submission = $this->receive($form, ['email' => 'hello@leadgen.example', 'message' => 'pitch']);

        // Mail scanners open every link on arrival: that must not block anyone.
        $this->get($submission->blockUrl())->assertOk()->assertSee('Block @leadgen.example?')->assertSee('Nothing is deleted');
        $this->assertFalse($submission->fresh()->spam);
        $this->assertNull($form->fresh()->spam_words);
    }

    public function test_a_changed_link_is_refused(): void
    {
        $form = Form::factory()->create();
        $submission = $this->receive($form, ['email' => 'hello@leadgen.example', 'message' => 'pitch']);
        $url = $submission->blockUrl();

        $this->post(str_replace('leadgen.example', 'bakery.example.org', $url))->assertForbidden();
        $this->post(str_replace('submissions/'.$submission->id, 'submissions/'.($submission->id + 1), $url))->assertForbidden();
        $this->post(route('submissions.block', ['submission' => $submission->id, 'sender' => '@bakery.example.org']))->assertForbidden();

        $this->assertFalse($submission->fresh()->spam);
        $this->assertNull($form->fresh()->spam_words);
    }

    public function test_it_still_blocks_on_a_form_that_kept_nothing(): void
    {
        $form = Form::factory()->create(['store_submissions' => false]);
        $submission = $this->receive($form, ['email' => 'hello@other-leadgen.example', 'message' => 'pitch']);
        $url = $submission->blockUrl();
        $submission->forget();

        $this->post($url)->assertOk();

        $this->assertSame(['@other-leadgen.example'], $form->fresh()->spam_words);
        $this->assertTrue($submission->fresh()->spam);
    }

    public function test_a_message_with_no_address_only_moves_to_spam(): void
    {
        $form = Form::factory()->create();
        $submission = $this->receive($form, ['message' => 'Buy followers']);

        $this->get($submission->blockUrl())->assertSee('Move this message to spam?');
        $this->post($submission->blockUrl())->assertOk()->assertSee('no sender to block');

        $this->assertTrue($submission->fresh()->spam);
        $this->assertNull($form->fresh()->spam_words);
    }

    public function test_a_full_spam_word_list_is_said_out_loud(): void
    {
        $form = Form::factory()->create(['spam_words' => array_map(fn ($i) => "word{$i}", range(1, FormSpamSettings::MAX_WORDS))]);
        $submission = $this->receive($form, ['email' => 'hello@leadgen.example', 'message' => 'pitch']);

        $this->post($submission->blockUrl())->assertOk()->assertSee('was not blocked')->assertSee('Remove some under Settings');

        $this->assertTrue($submission->fresh()->spam);
        $this->assertNotContains('@leadgen.example', $form->fresh()->spam_words);
    }

    public function test_the_owner_email_carries_the_link_and_the_client_copy_does_not(): void
    {
        $form = Form::factory()->create();
        $submission = $form->submissions()->create(['data' => ['email' => 'hello@leadgen.example', 'message' => 'pitch']]);
        $recipient = $form->recipients()->create(['email' => 'client@bakery.example.org']);
        $recipient->forceFill(['confirmed_at' => now()])->save();

        $owner = new SubmissionReceived($submission);
        $this->assertStringContainsString('Spam? Block this sender', $owner->render());
        $this->assertStringContainsString('submissions/'.$submission->id.'/block', $owner->render());
        $text = view('mail.submission-received-text', ['submission' => $submission])->render();
        $this->assertStringContainsString('Spam? Block this sender: '.url('submissions/'.$submission->id.'/block'), $text);

        $client = (new SubmissionReceived($submission, $recipient))->render();
        $this->assertStringNotContainsString('/block', $client);
    }

    public function test_the_dashboard_blocks_and_not_spam_undoes_it(): void
    {
        $form = Form::factory()->create(['spam_words' => ['crypto']]);
        $pitch = $form->submissions()->create(['data' => ['name' => 'Test Sender Three', 'email' => 'hello@leadgen.example', 'message' => 'Quick test pitch']]);
        $form->submissions()->create(['data' => ['email' => 'jan@bakery.example.org', 'message' => 'Real question']]);
        $this->actingAs($form->user);

        $page = Volt::test('forms.show', ['form' => $form])
            ->call('selectSubmission', $pitch->id)
            ->assertSee('Spam: block sender')
            ->call('blockSender', $pitch->id)
            ->assertSee('New messages from @leadgen.example')
            // The block shows in the Blocked senders list, not in the spam words textarea.
            ->assertSet('spam_words', 'crypto')
            ->assertDontSee('Quick test pitch')
            ->assertSee('Real question');

        $this->assertStringContainsString('@leadgen.example', $this->blockedList($page->html()));
        $this->assertTrue($pitch->fresh()->spam);

        $page->set('showSpam', true)
            ->call('selectSubmission', $pitch->id)
            ->assertSee('You marked it as spam.')
            ->call('notSpam', $pitch->id)
            ->assertSee('Moved back to the inbox.')
            ->assertSet('spam_words', 'crypto');

        $this->assertStringNotContainsString('@leadgen.example', $this->blockedList($page->html()));
        $this->assertFalse($pitch->fresh()->spam);
        $this->assertSame(['crypto'], $form->fresh()->spam_words);
    }

    public function test_the_settings_list_shows_blocked_senders_and_the_textarea_only_the_ordinary_words(): void
    {
        $form = Form::factory()->create(['spam_words' => ['seo services', '@leadgen.example', 'crypto', 'test-sender@gmx.com']]);
        $this->actingAs($form->user);

        $html = Volt::test('forms.show', ['form' => $form])
            ->assertSet('spam_words', "seo services\ncrypto")
            ->html();

        $list = $this->blockedList($html);
        $this->assertStringContainsString('@leadgen.example', $list);
        $this->assertStringContainsString('test-sender@gmx.com', $list);
        $this->assertStringNotContainsString('seo services', $list);
        $this->assertStringNotContainsString('crypto', $list);
        $this->assertSame(2, substr_count($list, 'wire:click="unblockSender('));
        // Two senders: no filter box until there are more than 10.
        $this->assertStringNotContainsString('Filter blocked senders', $list);
    }

    public function test_more_than_ten_blocked_senders_get_a_filter_box(): void
    {
        $form = Form::factory()->create(['spam_words' => array_map(fn ($i) => "@company{$i}.example", range(1, 11))]);
        $this->actingAs($form->user);

        $list = $this->blockedList(Volt::test('forms.show', ['form' => $form])->html());

        $this->assertStringContainsString('Filter blocked senders', $list);
        $this->assertSame(11, substr_count($list, 'wire:click="unblockSender('));
    }

    public function test_unblock_takes_off_exactly_one_entry_and_keeps_old_messages_in_spam(): void
    {
        $form = Form::factory()->create(['spam_words' => ['seo services', '@leadgen.example', 'test-sender@gmx.com', 'crypto']]);
        $old = $form->submissions()->create(['data' => ['email' => 'hello@leadgen.example', 'message' => 'pitch']]);
        $old->forceFill(['spam' => true, 'spam_reason' => 'marked'])->save();
        $this->actingAs($form->user);

        $page = Volt::test('forms.show', ['form' => $form])
            ->set('spam_words', "seo services\ncrypto\nunsaved typing")
            ->call('unblockSender', '@leadgen.example')
            ->assertSee('Unblocked @leadgen.example. New messages from it reach your inbox again. Messages it already sent stay in spam: press Not spam on one to move it back.')
            // Unsaved typing in the textarea is left alone.
            ->assertSet('spam_words', "seo services\ncrypto\nunsaved typing");

        $this->assertSame(['seo services', 'test-sender@gmx.com', 'crypto'], $form->fresh()->spam_words);
        $this->assertTrue($old->fresh()->spam);
        $list = $this->blockedList($page->html());
        $this->assertStringNotContainsString("unblockSender('@leadgen.example')", $list);
        $this->assertStringContainsString('test-sender@gmx.com', $list);

        // An ordinary word cannot be taken off through Unblock.
        $page->call('unblockSender', 'crypto');
        $this->assertContains('crypto', $form->fresh()->spam_words);

        // The last sender gone: the list says how to block one.
        $page->call('unblockSender', 'test-sender@gmx.com')->assertSee('To block one, press');
        $this->assertSame(['seo services', 'crypto'], $form->fresh()->spam_words);
    }

    public function test_saving_the_settings_keeps_the_blocked_senders(): void
    {
        $form = Form::factory()->create(['spam_words' => ['seo services', '@leadgen.example']]);
        $this->actingAs($form->user);

        $page = Volt::test('forms.show', ['form' => $form]);
        // Blocked from the email link while the settings page was open.
        $form->fresh()->update(['spam_words' => ['seo services', '@leadgen.example', '@other-leadgen.example']]);

        $page->set('spam_words', "crypto\n@typed.example")
            ->call('saveSettings')
            ->assertHasNoErrors()
            ->assertSet('spam_words', 'crypto');

        $this->assertEqualsCanonicalizing(['crypto', '@typed.example', '@leadgen.example', '@other-leadgen.example'], $form->fresh()->spam_words);
        $list = $this->blockedList($page->html());
        $this->assertStringContainsString('@typed.example', $list);
        $this->assertStringContainsString('@other-leadgen.example', $list);

        // Emptying the textarea clears the words, never the senders.
        $page->set('spam_words', '')->call('saveSettings')->assertHasNoErrors();
        $this->assertEqualsCanonicalizing(['@typed.example', '@leadgen.example', '@other-leadgen.example'], $form->fresh()->spam_words);
    }

    public function test_the_cap_counts_words_and_blocked_senders_together(): void
    {
        $senders = array_map(fn ($i) => "@company{$i}.example", range(1, 30));
        $form = Form::factory()->create(['spam_words' => $senders]);
        $this->actingAs($form->user);

        $page = Volt::test('forms.show', ['form' => $form])
            ->set('spam_words', implode("\n", array_map(fn ($i) => "word{$i}", range(1, FormSpamSettings::MAX_WORDS - 30 + 1))))
            ->call('saveSettings')
            ->assertHasErrors('spam_words')
            ->assertSee('List up to '.FormSpamSettings::MAX_WORDS.' spam words and blocked senders together.');
        $this->assertSame($senders, $form->fresh()->spam_words);

        $page->set('spam_words', implode("\n", array_map(fn ($i) => "word{$i}", range(1, FormSpamSettings::MAX_WORDS - 30))))
            ->call('saveSettings')
            ->assertHasNoErrors();
        $this->assertCount(FormSpamSettings::MAX_WORDS, $form->fresh()->spam_words);
    }

    public function test_a_form_with_nothing_blocked_says_how_to_block(): void
    {
        $form = Form::factory()->create(['spam_words' => ['crypto']]);
        $this->actingAs($form->user);

        $list = $this->blockedList(Volt::test('forms.show', ['form' => $form])->assertSet('spam_words', 'crypto')->html());

        $this->assertStringContainsString('To block one, press "Spam? Block this sender" in the email about a message, or "Spam: block sender" on a message under Submissions.', $list);
        $this->assertStringNotContainsString('wire:click="unblockSender(', $list);
        $this->assertStringNotContainsString('crypto', $list);
    }

    /** The Blocked senders block of the Settings tab, so assertions never match the textarea. */
    private function blockedList(string $html): string
    {
        $start = strpos($html, 'data-blocked-senders');
        $this->assertNotFalse($start, 'The Blocked senders list is missing.');

        return substr($html, $start, strpos($html, 'Data and privacy', $start) - $start);
    }

    public function test_the_dashboard_cannot_touch_another_accounts_message(): void
    {
        $mine = Form::factory()->create();
        $theirs = Form::factory()->create();
        $other = $theirs->submissions()->create(['data' => ['email' => 'hello@leadgen.example', 'message' => 'x']]);
        $this->actingAs($mine->user);

        Volt::test('forms.show', ['form' => $mine])->call('blockSender', $other->id)->assertNotFound();
        Volt::test('forms.show', ['form' => $mine])->call('notSpam', $other->id)->assertNotFound();

        $this->assertFalse($other->fresh()->spam);
        $this->assertNull($theirs->fresh()->spam_words);
    }
}
