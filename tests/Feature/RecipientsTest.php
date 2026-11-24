<?php

namespace Tests\Feature;

use App\Mail\RecipientConfirmation;
use App\Mail\SubmissionReceived;
use App\Models\Form;
use App\Models\FormRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RecipientsTest extends TestCase
{
    use RefreshDatabase;

    private function formWith(array $recipients, array $attributes = []): Form
    {
        $form = Form::factory()->for(User::factory()->create(['name' => 'Robin Test', 'email' => 'owner@agency.example']))->create($attributes);

        foreach ($recipients as $email => $confirmed) {
            $form->recipients()->create(['email' => $email])->forceFill(['confirmed_at' => $confirmed ? now() : null])->save();
        }

        return $form;
    }

    /** What actually left the (array) mailer: [to, from name, html]. */
    private function sent(): \Illuminate\Support\Collection
    {
        return collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($m) => [
            'to' => $m->getOriginalMessage()->getTo()[0]->getAddress(),
            'from' => $m->getOriginalMessage()->getFrom()[0]->getName(),
            'html' => $m->getOriginalMessage()->getHtmlBody(),
            'text' => $m->getOriginalMessage()->getTextBody(),
        ]);
    }

    public function test_an_unconfirmed_address_gets_nothing(): void
    {
        $form = $this->formWith(['client@bakery.example' => false]);

        $this->post("/f/{$form->public_id}", ['message' => 'Hi'])->assertRedirect();

        $this->assertSame(['owner@agency.example'], $this->sent()->pluck('to')->all());
    }

    public function test_a_confirmed_client_gets_a_copy_under_the_agency_name_without_our_branding(): void
    {
        $form = $this->formWith(['client@bakery.example' => true], ['sender_name' => 'Studio Noord']);

        $this->post("/f/{$form->public_id}", ['email' => 'anna@example.org', 'message' => 'Cake for 12?'], ['Referer' => 'https://bakery.example/contact'])->assertRedirect();

        $sent = $this->sent()->keyBy('to');
        $this->assertEqualsCanonicalizing(['owner@agency.example', 'client@bakery.example'], $sent->keys()->all());

        $client = $sent['client@bakery.example'];
        $this->assertSame('Studio Noord', $client['from']);
        $this->assertStringContainsString('Cake for 12?', $client['html']);
        $this->assertStringContainsString('Press Reply to answer anna@example.org', $client['html']);
        $this->assertStringContainsString('Stop these emails', $client['html']);
        $this->assertStringContainsString('deleted after '.Form::DEFAULT_RETENTION_DAYS.' days', $client['html']);
        // What the client reads: our name appears only inside the stop link's URL and the sending address.
        $this->assertStringNotContainsStringIgnoringCase('formhaven', strip_tags($client['html']));
        $this->assertStringNotContainsStringIgnoringCase('formhaven', preg_replace('#https://\S+#', '', $client['text']));
        $this->assertStringNotContainsString('All messages for this form', $client['html']);

        // The owner's own copy is unchanged: our name, the dashboard link.
        $this->assertStringContainsString('All messages for this form', $sent['owner@agency.example']['html']);
    }

    public function test_the_clients_copy_keeps_line_breaks_and_lists_no_subject_row(): void
    {
        $form = $this->formWith(['client@bakery.example' => true]);

        $this->post("/f/{$form->public_id}", ['message' => "Cake for 12?\nChocolate please", '_subject' => 'Order question'])->assertRedirect();

        $client = $this->sent()->keyBy('to')['client@bakery.example'];
        $this->assertStringContainsString('Cake for 12?<br>Chocolate please', $client['html']);
        $this->assertStringContainsString("Message:\nCake for 12?\nChocolate please", $client['text']);
        $this->assertStringNotContainsString('_subject', $client['html'].$client['text']);
    }

    public function test_without_a_sender_name_the_owners_account_name_is_used(): void
    {
        $form = $this->formWith(['client@bakery.example' => true]);

        $this->post("/f/{$form->public_id}", ['message' => 'Hi'])->assertRedirect();

        $this->assertSame('Robin Test', $this->sent()->firstWhere('to', 'client@bakery.example')['from']);
    }

    public function test_the_owner_can_switch_themselves_off_but_never_before_a_client_confirmed(): void
    {
        $confirmed = $this->formWith(['client@bakery.example' => true], ['notify_owner' => false]);
        $this->post("/f/{$confirmed->public_id}", ['message' => 'Hi'])->assertRedirect();
        $this->assertSame(['client@bakery.example'], $this->sent()->pluck('to')->all());

        app('mailer')->getSymfonyTransport()->flush();

        $pending = Form::factory()->for($confirmed->user)->create(['notify_owner' => false]);
        $pending->recipients()->create(['email' => 'late@client.example']);
        $this->post("/f/{$pending->public_id}", ['message' => 'Hi'])->assertRedirect();
        $this->assertSame(['owner@agency.example'], $this->sent()->pluck('to')->all());
    }

    public function test_a_form_that_stores_nothing_wipes_only_after_the_last_email(): void
    {
        $form = $this->formWith(['client@bakery.example' => true, 'boss@bakery.example' => true], ['store_submissions' => false]);
        $submission = $form->submissions()->create(['data' => ['message' => 'Private']]);
        $submission->forceFill(['emails_pending' => 3])->save();
        [$client, $boss] = $form->recipients()->get()->all();

        (new SubmissionReceived($submission))->to('owner@agency.example')->send(app('mailer'));
        (new SubmissionReceived($submission, $client))->to($client->email)->send(app('mailer'));
        $this->assertSame(['message' => 'Private'], $submission->fresh()->data, 'wiped before the last recipient got it');

        (new SubmissionReceived($submission, $boss))->to($boss->email)->send(app('mailer'));
        $this->assertSame([], $submission->fresh()->data);
        $this->assertCount(3, $this->sent());
    }

    public function test_a_client_who_stops_while_their_email_is_queued_gets_nothing_and_does_not_hold_the_wipe(): void
    {
        $form = $this->formWith(['client@bakery.example' => true], ['store_submissions' => false]);
        $submission = $form->submissions()->create(['data' => ['message' => 'Private']]);
        $submission->forceFill(['emails_pending' => 1])->save();
        $client = $form->recipients()->sole();

        // Through the queue's own serialization, as the worker would see it.
        $queued = unserialize(serialize((new SubmissionReceived($submission, $client))->to($client->email)));
        $client->delete();
        $queued->send(app('mailer'));

        $this->assertCount(0, $this->sent());
        $this->assertSame([], $submission->fresh()->data);
    }

    public function test_a_confirmation_for_an_address_removed_before_sending_is_not_sent(): void
    {
        $recipient = $this->formWith(['client@bakery.example' => false])->recipients()->sole();

        $queued = unserialize(serialize((new RecipientConfirmation($recipient))->to($recipient->email)));
        $recipient->delete();
        $queued->send(app('mailer'));

        $this->assertCount(0, $this->sent());
    }

    public function test_the_confirmation_states_the_forms_real_retention(): void
    {
        $keeps = $this->formWith(['a@client.example' => false], ['retention_days' => 90, 'site_url' => 'https://bakery.example']);
        $none = Form::factory()->for($keeps->user)->create(['store_submissions' => false]);
        $none->recipients()->create(['email' => 'b@client.example']);

        $keepsText = strip_tags((new RecipientConfirmation($keeps->recipients()->sole()))->render());
        $noneText = strip_tags((new RecipientConfirmation($none->recipients()->sole()))->render());

        $this->assertStringContainsString('"Contact form" on bakery.example to be emailed', $keepsText);
        $this->assertStringContainsString('stored in the EU and deleted after 90 days', $keepsText);
        $this->assertStringContainsString('stored in the EU and deleted once emailed', $noneText);
        $this->assertDoesNotMatchRegularExpression('/@(if|else|endif)/', $keepsText.$noneText, 'a Blade directive leaked into the email');
    }

    public function test_a_form_that_stores_nothing_mails_everyone_through_the_real_endpoint_then_wipes(): void
    {
        $form = $this->formWith(['client@bakery.example' => true], ['store_submissions' => false]);

        $this->post("/f/{$form->public_id}", ['message' => 'Private'])->assertRedirect();

        $this->assertCount(2, $this->sent());
        $this->sent()->each(fn ($mail) => $this->assertStringContainsString('Private', $mail['html']));
        $this->assertSame([], $form->submissions()->sole()->data);
    }

    public function test_opening_the_confirm_link_does_not_confirm_but_pressing_the_button_does(): void
    {
        $form = $this->formWith(['client@bakery.example' => false], ['sender_name' => 'Studio Noord']);
        $recipient = $form->recipients()->sole();
        $url = $recipient->confirmUrl();

        $this->get($url)->assertOk()->assertSee('Studio Noord wants every message')->assertSee('Yes, send them to me');
        $this->assertNull($recipient->fresh()->confirmed_at);

        $this->post($url)->assertOk()->assertSee('You will get these messages');
        $this->assertNotNull($recipient->fresh()->confirmed_at);
    }

    public function test_a_forged_or_expired_confirm_link_is_refused(): void
    {
        $recipient = $this->formWith(['client@bakery.example' => false])->recipients()->sole();

        $this->post(route('recipients.confirm', $recipient))->assertForbidden()->assertSee('This link is not complete');
        $this->post($recipient->confirmUrl().'x')->assertForbidden()->assertSee('Open it again from the email');
        $this->get('/recipients/abc/confirm')->assertNotFound();

        $url = $recipient->confirmUrl();
        $this->travel(FormRecipient::CONFIRM_DAYS + 1)->days();
        $this->get($url)->assertForbidden()->assertSee('This link has expired')->assertSee('Ask the person who added you to send a new one.');
        $this->post($url)->assertForbidden();
        $this->assertNull($recipient->fresh()->confirmed_at);
    }

    public function test_links_for_a_removed_address_explain_themselves(): void
    {
        $recipient = $this->formWith(['client@bakery.example' => false])->recipients()->sole();
        [$confirm, $stop] = [$recipient->confirmUrl(), $recipient->stopUrl()];
        $recipient->delete();

        $this->get($confirm)->assertNotFound()->assertSee('This address was removed from the form');
        $this->post($stop)->assertOk()->assertSee('This address gets no more messages from this form.');
    }

    public function test_a_clients_copy_carries_one_click_unsubscribe_headers_and_the_owners_does_not(): void
    {
        $form = $this->formWith(['client@bakery.example' => true]);
        $this->post("/f/{$form->public_id}", ['message' => 'Hi'])->assertRedirect();

        $messages = collect(app('mailer')->getSymfonyTransport()->messages())->keyBy(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress());
        $client = $messages['client@bakery.example']->getOriginalMessage()->getHeaders();
        $owner = $messages['owner@agency.example']->getOriginalMessage()->getHeaders();

        $this->assertSame('List-Unsubscribe=One-Click', $client->get('List-Unsubscribe-Post')->getBodyAsString());
        $this->assertNull($owner->get('List-Unsubscribe'));

        // What a mail app does with it: a bare POST to the URL in the header.
        $url = trim($client->get('List-Unsubscribe')->getBodyAsString(), '<>');
        $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk();
        $this->assertSame(0, $form->recipients()->count());
    }

    public function test_the_stop_link_in_a_clients_email_removes_them(): void
    {
        $form = $this->formWith(['client@bakery.example' => true]);
        $this->post("/f/{$form->public_id}", ['message' => 'Hi'])->assertRedirect();

        preg_match('#href="([^"]+/stop[^"]+)"#', $this->sent()->firstWhere('to', 'client@bakery.example')['html'], $m);
        $url = html_entity_decode($m[1]);

        $this->get($url)->assertOk()->assertSee('Stop these emails');
        $this->assertSame(1, $form->recipients()->count());
        $this->post($url)->assertOk()->assertSee('gets no more messages');
        $this->assertSame(0, $form->recipients()->count());
    }

    public function test_adding_an_address_emails_it_a_working_confirmation_link(): void
    {
        $form = $this->formWith([]);

        Volt::actingAs($form->user)->test('forms.show', ['form' => $form])
            ->set('recipient_email', ' Client@Bakery.example ')
            ->call('addRecipient')
            ->assertHasNoErrors()
            ->assertSee('We emailed client@bakery.example a link to confirm.');

        $mail = $this->sent()->sole();
        $this->assertSame('client@bakery.example', $mail['to']);
        $this->assertStringContainsString('Robin Test wants every message', $mail['html']);
        preg_match('#href="([^"]+/confirm[^"]+)"#', $mail['html'], $m);

        $this->post(html_entity_decode($m[1]))->assertOk();
        $this->assertTrue($form->recipients()->sole()->isConfirmed());
    }

    public function test_own_duplicate_and_one_too_many_addresses_are_refused(): void
    {
        Mail::fake();
        $form = $this->formWith(['a@client.example' => true, 'b@client.example' => false, 'c@client.example' => false, 'd@client.example' => false]);
        $page = Volt::actingAs($form->user)->test('forms.show', ['form' => $form]);

        $page->set('recipient_email', 'OWNER@agency.example')->call('addRecipient')->assertHasErrors('recipient_email');
        $page->set('recipient_email', 'A@client.example')->call('addRecipient')->assertHasErrors('recipient_email');
        $page->set('recipient_email', 'not an email')->call('addRecipient')->assertHasErrors('recipient_email');
        $page->set('recipient_email', 'e@client.example')->call('addRecipient')->assertHasNoErrors();
        $page->set('recipient_email', 'f@client.example')->call('addRecipient')->assertHasErrors('recipient_email');

        $this->assertSame(5, $form->recipients()->count());
        Mail::assertQueued(RecipientConfirmation::class, 1);
    }

    public function test_the_sender_name_refuses_header_characters_and_accepts_ordinary_names(): void
    {
        $form = $this->formWith([]);
        $page = Volt::actingAs($form->user)->test('forms.show', ['form' => $form]);

        foreach (['Evil <x@y.z>', 'Quote "me"', 'Back\\slash', "Two\nlines"] as $bad) {
            $page->set('sender_name', $bad)->call('saveEmails')->assertHasErrors('sender_name');
        }

        foreach (['Studio Noord', 'Bakkerij Rietveld & Zonen', "O'Reilly Webdesign"] as $good) {
            $page->set('sender_name', $good)->call('saveEmails')->assertHasNoErrors();
            $this->assertSame($good, $form->fresh()->sender_name);
        }
    }

    public function test_the_text_parts_carry_working_links_and_text_as_typed(): void
    {
        $form = $this->formWith(['client@bakery.example' => true], ['name' => 'Tom & "Jerry"']);
        $pending = $form->recipients()->create(['email' => 'new@bakery.example']);

        $this->post("/f/{$form->public_id}", ['message' => 'Fish & chips <for 2>'])->assertRedirect();
        (new \App\Mail\RecipientConfirmation($pending))->to($pending->email)->send(app('mailer'));

        $client = $this->sent()->firstWhere('to', 'client@bakery.example')['text'];
        $this->assertStringContainsString('Message: Fish & chips <for 2>', $client);
        $this->assertStringContainsString('Stop these emails: '.$form->recipients()->whereNotNull('confirmed_at')->sole()->stopUrl(), $client);

        $confirmation = $this->sent()->firstWhere('to', 'new@bakery.example')['text'];
        $this->assertStringContainsString('the form "Tom & "Jerry""', $confirmation);
        $this->assertMatchesRegularExpression('#Yes, send them to me: \S+/confirm\?expires=\d+&signature=\w+#', $confirmation);
        $this->assertStringNotContainsString('&amp;', $client.$confirmation);
    }

    public function test_typed_text_never_becomes_a_link_or_image_in_our_emails(): void
    {
        $bait = '[Verify your account](https://evil.example/login) ![](https://evil.example/pixel.png)';
        $form = $this->formWith(['client@bakery.example' => true], ['name' => $bait, 'sender_name' => '[Pay here](https://evil.example)']);
        $form->recipients()->create(['email' => 'new@bakery.example']);

        // Visitor answers and the form name, in the owner's copy and the client's copy.
        $this->post("/f/{$form->public_id}", ['message' => $bait, $bait => 'field name too'])->assertRedirect();
        // Form and sender name, in a confirmation to an address that never agreed to anything.
        (new \App\Mail\RecipientConfirmation($form->recipients()->where('email', 'new@bakery.example')->sole()))->to('new@bakery.example')->send(app('mailer'));

        $this->assertCount(3, $this->sent());

        // The owner's copy still shows ordinary text as typed, in both parts (found on a real inbox: "&amp;amp;", "anna\@example\.org").
        app('mailer')->getSymfonyTransport()->flush();
        $this->post("/f/{$form->public_id}", ['message' => 'Fish & chips <for 2>, anna@example.org: 100% sure'])->assertRedirect();
        $owner = $this->sent()->firstWhere('to', 'owner@agency.example');
        $this->assertStringContainsString('Fish &amp; chips &lt;for 2&gt;, anna@example.org: 100% sure', $owner['html']);
        $this->assertStringContainsString('Fish & chips <for 2>, anna@example.org: 100% sure', html_entity_decode($owner['text']));
        app('mailer')->getSymfonyTransport()->flush();
        $this->post("/f/{$form->public_id}", ['message' => $bait, $bait => 'field name too'])->assertRedirect();
        (new \App\Mail\RecipientConfirmation($form->recipients()->where('email', 'new@bakery.example')->sole()))->to('new@bakery.example')->send(app('mailer'));

        foreach ($this->sent() as $mail) {
            $this->assertStringNotContainsString('href="https://evil.example', $mail['html'], "link injected into the mail to {$mail['to']}");
            $this->assertStringNotContainsString('src="https://evil.example', $mail['html'], "image injected into the mail to {$mail['to']}");
            $this->assertStringContainsString('[Verify your account]', html_entity_decode($mail['html']), "the text is not shown as typed to {$mail['to']}");
        }
    }

    public function test_the_dashboard_test_names_everyone_it_was_sent_to(): void
    {
        $form = $this->formWith(['client@bakery.example' => true, 'pending@bakery.example' => false]);

        Volt::actingAs($form->user)->test('forms.show', ['form' => $form])
            ->call('sendTest')
            ->assertSee('an email is on its way to owner@agency.example and client@bakery.example.');
    }

    public function test_a_recipient_of_someone_elses_form_cannot_be_removed(): void
    {
        $mine = $this->formWith([]);
        $theirs = Form::factory()->create();
        $theirs->recipients()->create(['email' => 'x@example.org']);

        Volt::actingAs($mine->user)->test('forms.show', ['form' => $mine])
            ->call('removeRecipient', $theirs->recipients()->sole()->id);

        $this->assertSame(1, $theirs->recipients()->count());
    }
}
