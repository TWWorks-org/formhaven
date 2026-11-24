<?php

namespace Tests\Feature;

use App\Mcp\Servers\FormHavenServer;
use App\Mcp\Tools\ListFormsTool;
use App\Mcp\Tools\UpdateFormEmailsTool;
use App\Models\Form;
use App\Models\FormRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** The read-only page of a form's messages that a confirmed recipient can open from its emails. */
class RecipientMessagesTest extends TestCase
{
    use RefreshDatabase;

    private function formWithClient(array $attributes = [], bool $confirmed = true): array
    {
        $form = Form::factory()->for(User::factory()->create(['name' => 'Robin Test', 'email' => 'owner@agency.example']))
            ->create(['name' => 'Bakery contact', 'sender_name' => 'Studio Noord', ...$attributes]);
        $recipient = $form->recipients()->create(['email' => 'client@bakery.example']);
        $recipient->forceFill(['confirmed_at' => $confirmed ? now() : null])->save();

        return [$form, $recipient];
    }

    private function message(Form $form, array $data, bool $spam = false, $at = null): void
    {
        $submission = $form->submissions()->create(['data' => $data, 'spam' => $spam]);
        $submission->forceFill(['created_at' => $at ?? now()])->save();
    }

    private function key(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /** What the (array) mailer sent to one address: [html, text]. */
    private function mailTo(string $address): array
    {
        $message = collect(app('mailer')->getSymfonyTransport()->messages())
            ->map->getOriginalMessage()
            ->first(fn ($m) => $m->getTo()[0]->getAddress() === $address);

        return [$message->getHtmlBody(), $message->getTextBody()];
    }

    public function test_the_setting_is_off_by_default_and_the_link_then_explains_who_to_ask(): void
    {
        [$form, $recipient] = $this->formWithClient();
        $this->message($form, ['message' => 'A secret order']);

        $this->assertFalse($form->fresh()->recipients_can_view);

        $this->get($recipient->messagesUrl())
            ->assertForbidden()
            ->assertSee('This page is not available')
            ->assertSee('Studio Noord has not turned on this page')
            ->assertDontSee('A secret order');
    }

    public function test_when_on_it_lists_the_forms_real_messages_newest_first_with_every_field(): void
    {
        [$form, $recipient] = $this->formWithClient(['recipients_can_view' => true]);
        $this->message($form, ['name' => 'Anna', 'email' => 'anna@example.org', 'message' => "Cake for 12?\nOn Friday."], at: now()->subHour());
        $this->message($form, ['name' => 'Bram', 'phone' => '0612345678', 'colour' => ['red', 'blue']]);
        $this->message($form, ['message' => 'Cheap pills'], spam: true);

        $response = $this->get($recipient->messagesUrl())->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertSee('Bakery contact')
            ->assertSee('From Anna')
            ->assertSee('Cake for 12?<br>On Friday.', false)
            ->assertSee('anna@example.org')
            ->assertSee('0612345678')
            ->assertSee('red, blue')
            ->assertDontSee('Cheap pills');

        $this->assertLessThan(strpos($response->getContent(), 'From Anna'), strpos($response->getContent(), 'From Bram'));
        // No session, so no cookie, for a page anyone with the link may open.
        $this->assertSame([], $response->headers->getCookies());
        // Like the client's emails: the owner's name, none of ours.
        $this->assertStringNotContainsStringIgnoringCase('formhaven', strip_tags($response->getContent()));
    }

    public function test_it_counts_this_month_and_last_month_and_says_when_retention_cut_a_month_short(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 20)->setTime(12, 0));
        [$form, $recipient] = $this->formWithClient(['recipients_can_view' => true, 'retention_days' => 365]);
        $this->message($form, ['message' => 'one'], at: now()->subDays(2));
        $this->message($form, ['message' => 'two'], at: now()->subDays(3));
        $this->message($form, ['message' => 'three'], at: now()->setDate(2026, 10, 10));
        $this->message($form, ['message' => 'spam'], spam: true, at: now()->subDay());
        $this->message($form, ['message' => 'september'], at: now()->setDate(2026, 9, 15));

        $html = $this->get($recipient->messagesUrl())->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<b>2</b>\s*<span class="muted">This month</span>#', $html);
        $this->assertMatchesRegularExpression('#<b>1</b>\s*<span class="muted">Last month</span>#', $html);

        // With 30 days kept, October is only partly stored, and the page says so.
        $form->update(['retention_days' => 30]);
        $this->get($recipient->messagesUrl())->assertOk()->assertSee('Last month (messages are kept 30 days)')->assertDontSee('This month (');
    }

    public function test_another_forms_messages_are_never_shown(): void
    {
        [$form, $recipient] = $this->formWithClient(['recipients_can_view' => true]);
        $other = Form::factory()->for($form->user)->create(['name' => 'Other site']);
        $this->message($other, ['message' => 'Not for the bakery']);
        $this->message($form, ['message' => 'For the bakery']);

        $this->get($recipient->messagesUrl())->assertOk()->assertSee('For the bakery')->assertDontSee('Not for the bakery');
    }

    public function test_the_link_needs_a_valid_signature_but_paging_keeps_working(): void
    {
        [$form, $recipient] = $this->formWithClient(['recipients_can_view' => true]);
        foreach (range(1, 25) as $n) {
            $this->message($form, ['message' => "Message number {$n}"], at: now()->subMinutes(100 - $n));
        }
        $url = $recipient->messagesUrl();

        $this->get(route('recipients.messages', $recipient))->assertForbidden()->assertSee('This link is not complete')->assertDontSee('Message number');
        $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden();
        // Another recipient's id under this signature.
        $this->get(str_replace("/recipients/{$recipient->id}/", '/recipients/'.($recipient->id + 1).'/', $url))->assertForbidden();

        $first = $this->get($url)->assertOk()->assertSee('Message number 25')->assertDontSee('Message number 5<', false)->assertSee('Page 1 of 2');
        preg_match('#href="([^"]+)" rel="next"#', $first->getContent(), $next);
        $this->get(html_entity_decode($next[1]))->assertOk()->assertSee('Message number 5')->assertDontSee('Message number 25')->assertSee('Page 2 of 2');
        $this->get($url.'&page=9')->assertOk()->assertSee('No messages on this page');
    }

    public function test_a_removed_or_unconfirmed_recipient_is_refused(): void
    {
        [$form, $recipient] = $this->formWithClient(['recipients_can_view' => true], confirmed: false);
        $this->message($form, ['message' => 'A secret order']);
        $url = $recipient->messagesUrl();

        $this->get($url)->assertForbidden()->assertSee('has not confirmed')->assertDontSee('A secret order');

        $recipient->delete();
        $this->get($url)->assertNotFound()->assertSee('This address was removed from the form')->assertDontSee('A secret order');
    }

    public function test_switching_the_setting_off_closes_a_link_that_already_went_out(): void
    {
        [$form, $recipient] = $this->formWithClient(['recipients_can_view' => true]);
        $this->message($form, ['message' => 'A secret order']);
        $url = $recipient->messagesUrl();
        $this->get($url)->assertOk()->assertSee('A secret order');

        $form->update(['recipients_can_view' => false]);
        $this->get($url)->assertForbidden()->assertDontSee('A secret order');
    }

    public function test_a_form_that_keeps_nothing_says_so_and_still_counts(): void
    {
        [$form, $recipient] = $this->formWithClient(['recipients_can_view' => true, 'store_submissions' => false]);
        $this->post("/f/{$form->public_id}", ['message' => 'Emailed then wiped'])->assertRedirect();

        $this->get($recipient->messagesUrl())->assertOk()
            ->assertSee('Messages are not kept for this form')
            ->assertSee('Not kept: the answers are deleted once the email is sent.')
            ->assertDontSee('Emailed then wiped')
            ->assertDontSee('No messages yet');
        $this->assertMatchesRegularExpression('#<b>1</b>\s*<span class="muted">This month#', $this->get($recipient->messagesUrl())->getContent());
    }

    public function test_empty_states_say_why_the_page_is_empty(): void
    {
        [$form, $recipient] = $this->formWithClient(['recipients_can_view' => true, 'retention_days' => 7]);

        $this->get($recipient->messagesUrl())->assertOk()->assertSee('No messages yet')->assertDontSee('No messages from the last');

        // Messages came in once, then the retention days removed them.
        $form->forceFill(['messages_total' => 3, 'last_message_at' => now()->subDays(10)])->save();
        $this->get($recipient->messagesUrl())->assertOk()
            ->assertSee('No messages from the last 7 days')
            ->assertSee('Messages are deleted after 7 days')
            ->assertDontSee('No messages yet');
    }

    public function test_the_clients_email_links_to_the_page_only_when_it_is_on_and_the_owners_never_does(): void
    {
        [$form, $recipient] = $this->formWithClient();
        $path = '/recipients/'.$recipient->id.'/messages';

        $this->post("/f/{$form->public_id}", ['message' => 'First'])->assertRedirect();
        [$html, $text] = $this->mailTo('client@bakery.example');
        $this->assertStringNotContainsString($path, $html.$text);

        app('mailer')->getSymfonyTransport()->flush();
        $form->update(['recipients_can_view' => true]);
        $this->post("/f/{$form->public_id}", ['message' => 'Second'])->assertRedirect();

        [$html, $text] = $this->mailTo('client@bakery.example');
        $this->assertStringContainsString(e($recipient->messagesUrl()), $html);
        $this->assertStringContainsString('open your messages page', $html);
        $this->assertStringContainsString($recipient->messagesUrl(), $text);
        // The link works as it was sent.
        $this->get($recipient->messagesUrl())->assertOk()->assertSee('Second');

        [$ownerHtml, $ownerText] = $this->mailTo('owner@agency.example');
        $this->assertStringNotContainsString($path, $ownerHtml.$ownerText);
    }

    public function test_rest_switches_it_and_reports_it_with_each_recipients_link(): void
    {
        [$form, $recipient] = $this->formWithClient();
        $form->recipients()->create(['email' => 'waiting@bakery.example']);
        $headers = $this->key($form->user);
        $base = "/api/v1/forms/{$form->public_id}";

        $this->getJson($base, $headers)->assertOk()
            ->assertJsonPath('data.recipients_can_view', false)
            ->assertJsonPath('data.recipients.0.messages_url', null);

        $this->patchJson($base, ['recipients_can_view' => 'yes please'], $headers)->assertUnprocessable()->assertJsonValidationErrors('recipients_can_view');

        $this->patchJson($base, ['recipients_can_view' => true], $headers)->assertOk()
            ->assertJsonPath('data.recipients_can_view', true)
            ->assertJsonPath('data.recipients.0.messages_url', $recipient->messagesUrl())
            // Not confirmed yet: no page for it.
            ->assertJsonPath('data.recipients.1.messages_url', null);
        $this->assertTrue($form->fresh()->recipients_can_view);
    }

    public function test_mcp_switches_it_and_reports_it(): void
    {
        [$form, $recipient] = $this->formWithClient();
        $user = $form->user;

        FormHavenServer::actingAs($user)->tool(UpdateFormEmailsTool::class, ['form_id' => $form->public_id, 'recipients_can_view' => true])
            ->assertOk()->assertSee('"recipients_can_view":true');
        $this->assertTrue($form->fresh()->recipients_can_view);

        FormHavenServer::actingAs($user)->tool(ListFormsTool::class)->assertOk()->assertSee('/recipients/'.$recipient->id.'/messages');

        FormHavenServer::actingAs($user)->tool(UpdateFormEmailsTool::class, ['form_id' => $form->public_id, 'recipients_can_view' => false])->assertOk();
        $this->assertFalse($form->fresh()->recipients_can_view);
    }

    public function test_the_dashboard_switches_it_and_offers_each_confirmed_recipients_link(): void
    {
        [$form, $recipient] = $this->formWithClient();
        $this->actingAs($form->user);

        Volt::test('forms.show', ['form' => $form])->assertDontSee('Copy page link')
            ->set('recipients_can_view', true)->call('saveEmails')->assertHasNoErrors();
        $this->assertTrue($form->fresh()->recipients_can_view);

        Volt::test('forms.show', ['form' => $form->fresh()])->assertSee('Copy page link')->assertSee(\Illuminate\Support\Str::after($recipient->messagesUrl(), "signature="), false);
    }
}
