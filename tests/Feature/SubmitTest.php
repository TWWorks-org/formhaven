<?php

namespace Tests\Feature;

use App\Mail\SubmissionReceived;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SubmitTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plain_html_post_is_stored_mailed_and_redirected_to_the_thanks_page(): void
    {
        Mail::fake();
        $form = Form::factory()->create();

        $response = $this->post("/f/{$form->public_id}", [
            'email' => 'visitor@example.org',
            'message' => 'Hello there',
            '_gotcha' => '',
        ]);

        $response->assertRedirect(route('submit.thanks'));
        $submission = $form->submissions()->sole();
        $this->assertSame(['email' => 'visitor@example.org', 'message' => 'Hello there'], $submission->data);
        $this->assertFalse($submission->spam);
        Mail::assertQueued(SubmissionReceived::class, fn ($mail) => $mail->hasTo($form->user->email)
            && $mail->hasReplyTo('visitor@example.org'));
    }

    public function test_a_submission_sets_no_cookie_and_keeps_the_owner_logged_in(): void
    {
        Mail::fake();
        config(['session.driver' => 'database']);
        $form = Form::factory()->create();

        $response = $this->post("/f/{$form->public_id}", ['message' => 'Hi']);

        $this->assertSame([], $response->headers->getCookies(), 'The form endpoint must not set cookies');
        $this->assertSame(0, \DB::table('sessions')->count(), 'The form endpoint must not create sessions');
    }

    /** The owner's email as delivered: [html, text]. */
    private function sentOwnerMail(Submission $submission): array
    {
        Mail::to($submission->form->user)->send(new SubmissionReceived($submission));
        $message = collect(app('mailer')->getSymfonyTransport()->messages())->last()->getOriginalMessage();

        return [$message->getHtmlBody(), $message->getTextBody()];
    }

    public function test_the_mail_lists_every_answer(): void
    {
        $form = Form::factory()->create(['name' => 'Quote request']);
        $submission = $form->submissions()->create(['data' => ['name' => 'Anna', 'message' => "Line one\nLine two"]]);

        $html = (new SubmissionReceived($submission))->render();

        $this->assertStringContainsString('Quote request', $html);
        $this->assertStringContainsString('Anna', $html);
    }

    public function test_the_owners_mail_keeps_the_visitors_line_breaks(): void
    {
        $form = Form::factory()->create();
        $submission = $form->submissions()->create(['data' => [
            'message' => "Line one\r\nLine two\n\nAfter a blank line",
            'address' => "Main St 1\rOld Mac line",
        ]]);

        [$html, $text] = $this->sentOwnerMail($submission);

        $this->assertStringContainsString('Line one<br>Line two<br><br>After a blank line', $html);
        $this->assertStringContainsString('Main St 1<br>Old Mac line', $html);
        $this->assertStringContainsString("Message:\nLine one\nLine two\n\nAfter a blank line", $text);
        $this->assertStringNotContainsString('<br>', $text);
        $this->assertStringNotContainsString('| Field |', $text, 'The text part reads as lines, not as Markdown table source');
    }

    public function test_line_breaks_cannot_smuggle_html_or_break_the_table(): void
    {
        $form = Form::factory()->create();
        $submission = $form->submissions()->create(['data' => [
            'message' => "<b>bold</b>\n<script>x</script> | not a column\n[Pay here](https://evil.example)",
        ]]);

        [$html, $text] = $this->sentOwnerMail($submission);

        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;<br>&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('href="https://evil.example"', $html);
        $this->assertStringContainsString('| not a column', $html, 'Shown as typed: answers are no longer a Markdown table');
        $this->assertStringContainsString("<b>bold</b>\n<script>x</script> | not a column", $text, 'The text part shows what was typed');
    }

    public function test_the_subject_field_is_the_subject_line_not_an_answer_row(): void
    {
        $form = Form::factory()->create();
        $submission = $form->submissions()->create(['data' => ['message' => 'Hi', '_subject' => 'Contact Form: Hello', '_replyto' => 'anna@example.org']]);

        [$html, $text] = $this->sentOwnerMail($submission);

        $this->assertStringNotContainsString('_subject', $html.$text);
        $this->assertStringContainsString('Replyto', $html, 'Only _subject is left out: the reply-to address is an answer');
        $this->assertSame('Hi', $submission->fresh()->data['message']);
        $this->assertSame('Contact Form: Hello', $submission->fresh()->data['_subject'], 'The stored answers and the API keep it');
    }

    public function test_a_json_post_gets_a_json_answer_with_cors(): void
    {
        Mail::fake();
        $form = Form::factory()->create();

        $this->postJson("/f/{$form->public_id}", ['message' => 'Hi'], ['Origin' => 'https://client-site.nl'])
            ->assertOk()
            ->assertJson(['ok' => true])
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_the_configured_thank_you_page_is_used(): void
    {
        Mail::fake();
        $form = Form::factory()->create(['redirect_url' => 'https://client-site.nl/bedankt']);

        $this->post("/f/{$form->public_id}", ['message' => 'Hi'])
            ->assertRedirect('https://client-site.nl/bedankt');
    }

    public function test_a_visitor_cannot_choose_where_they_are_redirected(): void
    {
        Mail::fake();
        $form = Form::factory()->create();

        $this->post("/f/{$form->public_id}", ['message' => 'Hi', '_redirect' => 'https://evil.example'])
            ->assertRedirect(route('submit.thanks'));
    }

    public function test_a_filled_honeypot_is_kept_as_spam_and_not_mailed(): void
    {
        Mail::fake();
        $form = Form::factory()->create();

        $this->postJson("/f/{$form->public_id}", ['message' => 'Buy now', '_gotcha' => 'http://spam.example'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertTrue($form->submissions()->sole()->spam);
        Mail::assertNothingQueued();
    }

    public function test_an_unknown_form_answers_404_in_json_and_html(): void
    {
        $this->postJson('/f/doesnotexist', ['message' => 'Hi'])
            ->assertNotFound()
            ->assertJson(['ok' => false]);

        $this->post('/f/doesnotexist', ['message' => 'Hi'])
            ->assertNotFound()
            ->assertSee('No form exists at this address');
    }

    public function test_an_empty_submission_is_refused_with_a_reason(): void
    {
        $form = Form::factory()->create();

        $this->postJson("/f/{$form->public_id}", ['_gotcha' => ''])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'The submission had no fields. Give each input a name attribute.']);

        $this->assertSame(0, $form->submissions()->count());
    }

    public function test_long_answers_are_cut_and_too_many_fields_refused(): void
    {
        Mail::fake();
        $form = Form::factory()->create();

        $this->postJson("/f/{$form->public_id}", ['message' => str_repeat('a', 6000)])->assertOk();
        $this->assertSame(5000, strlen($form->submissions()->sole()->data['message']));

        $fields = collect(range(1, 51))->mapWithKeys(fn ($i) => ["field{$i}" => 'x'])->all();
        $this->postJson("/f/{$form->public_id}", $fields)->assertStatus(422);
    }

    public function test_formspree_reply_to_and_subject_fields_work(): void
    {
        Mail::fake();
        $form = Form::factory()->create(['name' => 'Contact']);

        $this->post("/f/{$form->public_id}", [
            'name' => 'Anna',
            '_replyto' => ' anna@example.org ',
            '_subject' => "New lead\r\nBcc: x@evil.example",
        ])->assertRedirect();

        $submission = $form->submissions()->sole();
        $this->assertSame('anna@example.org', $submission->data['_replyto']);
        Mail::assertQueued(SubmissionReceived::class, fn ($mail) => $mail->hasReplyTo('anna@example.org')
            && $mail->envelope()->subject === 'New lead Bcc: x@evil.example | Contact');
    }

    public function test_without_a_subject_field_the_mail_names_the_form(): void
    {
        $form = Form::factory()->create(['name' => 'Quote request']);
        $submission = $form->submissions()->create(['data' => ['message' => 'Hi']]);

        $this->assertSame('New submission: Quote request', (new SubmissionReceived($submission))->envelope()->subject);
    }

    public function test_a_subject_or_reply_to_alone_is_still_an_empty_submission(): void
    {
        $form = Form::factory()->create();

        $this->postJson("/f/{$form->public_id}", ['_subject' => 'Hi', '_replyto' => 'a@example.org'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'EMPTY');
    }

    public function test_next_redirects_only_within_the_owners_site_or_the_sending_site(): void
    {
        Mail::fake();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class); // more posts than the per-minute limit
        $form = Form::factory()->create(['site_url' => 'https://www.client-site.nl']);
        $post = fn (string $next, array $headers = []) => $this->post("/f/{$form->public_id}", ['message' => 'Hi', '_next' => $next], $headers);

        // The saved site, with or without www.
        $post('https://client-site.nl/bedankt')->assertRedirect('https://client-site.nl/bedankt');
        $post('https://www.client-site.nl/thanks')->assertRedirect('https://www.client-site.nl/thanks');
        // The site the form was sent from.
        $post('https://other-client.nl/thanks', ['Referer' => 'https://other-client.nl/contact'])->assertRedirect('https://other-client.nl/thanks');
        // A relative path opens on the saved site.
        $post('/thanks')->assertRedirect('https://www.client-site.nl/thanks');
        // Anywhere else, or a script URL: our thank-you page.
        // Including URLs where PHP and browsers disagree on the host: a browser reads "\" as "/",
        // so "https://evil.example\@client-site.nl" opens evil.example although parse_url says client-site.nl.
        foreach (['https://evil.example/', 'https://client-site.nl.evil.example/', 'javascript:alert(1)',
            'https://evil.example\\@client-site.nl/', 'https://evil.example@client-site.nl/', 'https://client-site.nl\\.evil.example/',
            "https://client-site.nl/\nLocation: https://evil.example", 'https://client-site.nl /thanks', '//client-site.nl/thanks'] as $bad) {
            $response = $post($bad);
            $this->assertStringStartsWith(route('submit.thanks'), (string) $response->headers->get('Location'), $bad.' answered '.$response->status());
        }
    }

    public function test_the_owners_thank_you_page_wins_over_next(): void
    {
        Mail::fake();
        $form = Form::factory()->create(['site_url' => 'https://client-site.nl', 'redirect_url' => 'https://client-site.nl/bedankt']);

        $this->post("/f/{$form->public_id}", ['message' => 'Hi', '_next' => 'https://client-site.nl/other'])
            ->assertRedirect('https://client-site.nl/bedankt');
    }

    public function test_json_success_says_where_to_go_next(): void
    {
        Mail::fake();
        $plain = Form::factory()->create();
        $saved = Form::factory()->create(['redirect_url' => 'https://client-site.nl/bedankt']);

        $this->postJson("/f/{$plain->public_id}", ['message' => 'Hi'])->assertJson(['ok' => true, 'next' => route('submit.thanks')]);
        $this->postJson("/f/{$saved->public_id}", ['message' => 'Hi'])->assertJson(['ok' => true, 'next' => 'https://client-site.nl/bedankt']);
    }

    public function test_a_mistyped_email_is_refused_with_a_formspree_style_error(): void
    {
        Mail::fake();
        $form = Form::factory()->create();

        foreach (['not-an-email', 'anna@', 'anna example.org'] as $bad) {
            $this->postJson("/f/{$form->public_id}", ['email' => $bad, 'message' => 'Hi'])
                ->assertStatus(422)
                ->assertJson(['ok' => false, 'errors' => [['code' => 'TYPE_EMAIL', 'field' => 'email']]])
                ->assertJsonPath('error', fn ($error) => str_contains($error, 'is not valid'));
        }
        $this->postJson("/f/{$form->public_id}", ['_replyto' => 'nope', 'message' => 'Hi'])->assertJsonPath('errors.0.field', '_replyto');

        $this->postJson("/f/{$form->public_id}", ['email' => 'nope', 'message' => 'Hi'])
            ->assertJsonPath('message', 'The email address "nope" is not valid.');

        $this->post("/f/{$form->public_id}", ['email' => 'nope', 'message' => 'Hi'])
            ->assertStatus(422)
            ->assertSee('The email address &quot;nope&quot; is not valid. Use your browser&#039;s Back button', false);

        $this->assertSame(0, $form->submissions()->count());
    }

    public function test_an_empty_optional_email_and_a_spaced_valid_one_are_accepted(): void
    {
        Mail::fake();
        $form = Form::factory()->create();

        $this->postJson("/f/{$form->public_id}", ['email' => '', 'message' => 'No address'])->assertOk();
        $this->postJson("/f/{$form->public_id}", ['email' => "anna@example.org \n", 'message' => 'Spaced'])->assertOk();

        $this->assertSame('anna@example.org', $form->submissions()->latest('id')->first()->data['email']);
    }

    /**
     * A bad address gets the same answer whatever the filters would have said, so a bot cannot use
     * it to learn whether it passed them. The axis is which filter would have caught the post.
     * (Until 2026-11-24 spam with a bad address got the success answer and real mail a 422.)
     */
    public function test_a_bad_email_gets_the_same_answer_whether_or_not_the_post_is_spam(): void
    {
        Mail::fake();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $form = Form::factory()->create(['allowed_domains' => ['client-site.nl'], 'spam_words' => ['casino'], 'bot_check' => true]);
        $base = ['email' => 'nope', 'message' => 'Hello'];
        $answer = fn (array $input, array $headers = []) => $this->postJson("/f/{$form->public_id}", $input, $headers);

        // A form with no filters on, where this post is real mail: the baseline every spam case must match.
        $plain = Form::factory()->create();
        $real = $this->postJson("/f/{$plain->public_id}", $base, ['Origin' => 'https://client-site.nl']);
        $real->assertStatus(422)->assertJsonPath('errors.0.code', 'TYPE_EMAIL')->assertJsonPath('message', 'The email address "nope" is not valid.');

        foreach ([
            'honeypot' => [$base + ['_gotcha' => 'x'], ['Origin' => 'https://client-site.nl']],
            'site not allowed' => [$base, ['Origin' => 'https://copycat.example']],
            'bot check failed' => [$base, ['Origin' => 'https://client-site.nl']],
            'spam word' => [['email' => 'nope', 'message' => 'Best casino'], ['Origin' => 'https://client-site.nl']],
            'link markup' => [['email' => 'nope', 'message' => '[url=https://x.example]x[/url]'], ['Origin' => 'https://client-site.nl']],
        ] as $case => [$input, $headers]) {
            $response = $answer($input, $headers);
            $this->assertSame(422, $response->status(), $case);
            $this->assertSame($real->json(), $response->json(), $case);
        }

        $this->assertSame(0, $form->submissions()->count());
    }

    public function test_control_spam_with_a_valid_or_empty_email_still_gets_the_success_answer(): void
    {
        Mail::fake();
        $form = Form::factory()->create();

        $this->postJson("/f/{$form->public_id}", ['email' => 'anna@example.org', '_gotcha' => 'x'])->assertOk()->assertJson(['ok' => true]);
        $this->postJson("/f/{$form->public_id}", ['message' => 'Hi', '_gotcha' => 'x'])->assertOk()->assertJson(['ok' => true]);
        $this->assertSame([true, true], $form->submissions()->pluck('spam')->all());
    }

    public function test_errors_carry_a_code_for_every_refusal(): void
    {
        $form = Form::factory()->create();

        $this->postJson('/f/doesnotexist', ['message' => 'Hi'])->assertJsonPath('errors.0.code', 'FORM_NOT_FOUND');
        $this->postJson("/f/{$form->public_id}", [])->assertJsonPath('errors.0.code', 'EMPTY');
        $fields = collect(range(1, 51))->mapWithKeys(fn ($i) => ["field{$i}" => 'x'])->all();
        $this->postJson("/f/{$form->public_id}", $fields)->assertJsonPath('errors.0.code', 'TOO_MANY_FIELDS');
    }

    public function test_the_mail_names_the_page_the_form_was_sent_from(): void
    {
        $form = Form::factory()->create();
        $with = $form->submissions()->create(['data' => ['m' => 'Hi'], 'referer' => 'https://client-site.nl/contact/?utm_source=ad&token=secret']);
        $without = $form->submissions()->create(['data' => ['m' => 'Hi'], 'referer' => '']);

        $html = (new SubmissionReceived($with))->render();
        $this->assertStringContainsString('Contact form | client-site.nl/contact', $html);
        $this->assertStringNotContainsString('secret', $html);
        $this->assertStringNotContainsString('client-site.nl', (new SubmissionReceived($without))->render());
    }

    public function test_a_form_that_stores_nothing_emails_the_answers_then_wipes_them(): void
    {
        $form = Form::factory()->create(['store_submissions' => false]);

        $this->post("/f/{$form->public_id}", ['name' => 'Anna', 'message' => 'Private question'], ['Referer' => 'https://client-site.nl/contact'])
            ->assertRedirect();

        $sent = collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($m) => $m->getOriginalMessage()->getHtmlBody());
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Private question', $sent->first());
        $this->assertStringContainsString('Not kept: deleted from FormHaven once this email is sent', $sent->first());

        $submission = $form->submissions()->sole(); // the row stays: the monthly limit counts it
        $this->assertSame([], $submission->data);
        $this->assertNull($submission->referer);
        $this->assertNull($submission->user_agent);
        $this->assertSame('', (string) $submission->search_text);
        $this->assertFalse($submission->toApi()['stored']);
        $this->assertSame(1, $form->user->submissionsThisMonth());
    }

    public function test_a_form_that_stores_nothing_keeps_a_message_it_could_not_email(): void
    {
        $form = Form::factory()->for(User::factory()->unverified())->create(['store_submissions' => false]);

        $this->post("/f/{$form->public_id}", ['message' => 'Do not lose me'])->assertRedirect();

        $this->assertSame(['message' => 'Do not lose me'], $form->submissions()->sole()->data);
    }

    public function test_a_form_that_stores_nothing_keeps_no_spam(): void
    {
        $form = Form::factory()->create(['store_submissions' => false]);

        $this->post("/f/{$form->public_id}", ['message' => 'Buy pills', '_gotcha' => 'x'], ['Referer' => 'https://spam.example'])->assertRedirect();

        $spam = $form->submissions()->sole();
        $this->assertTrue($spam->spam);
        $this->assertSame([], $spam->data);
        $this->assertNull($spam->referer);
    }

    public function test_a_failed_send_keeps_the_answers_for_the_retry(): void
    {
        $form = Form::factory()->create(['store_submissions' => false]);
        $submission = $form->submissions()->create(['data' => ['message' => 'Retry me']]);
        $mailer = \Mockery::mock(\Illuminate\Contracts\Mail\Mailer::class);
        $mailer->shouldReceive('send')->andThrow(new \RuntimeException('Cloudflare is down'));

        try {
            (new SubmissionReceived($submission))->send($mailer);
            $this->fail('The send should have thrown');
        } catch (\RuntimeException) {
        }

        $this->assertSame(['message' => 'Retry me'], $submission->fresh()->data);
    }

    public function test_a_form_that_stores_submissions_keeps_them_after_the_email(): void
    {
        $form = Form::factory()->create();

        $this->post("/f/{$form->public_id}", ['message' => 'Keep me'])->assertRedirect();

        $this->assertSame(['message' => 'Keep me'], $form->submissions()->sole()->data);
        $html = app('mailer')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('deleted after '.Form::DEFAULT_RETENTION_DAYS.' days', $html);
    }

    public function test_floods_are_rate_limited(): void
    {
        Mail::fake();
        $form = Form::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/f/{$form->public_id}", ['message' => "Hi {$i}"])->assertOk();
        }

        $this->postJson("/f/{$form->public_id}", ['message' => 'one too many'])->assertStatus(429);
        $this->assertSame(10, $form->submissions()->count());
    }

    public function test_nothing_is_mailed_to_an_unverified_address(): void
    {
        Mail::fake();
        $form = Form::factory()->for(User::factory()->unverified())->create();

        $this->postJson("/f/{$form->public_id}", ['message' => 'Hi'])->assertOk();

        Mail::assertNothingQueued();
    }

    public function test_expired_submissions_are_pruned_per_form(): void
    {
        $short = Form::factory()->create(['retention_days' => 7]);
        $long = Form::factory()->create(['retention_days' => 90]);

        foreach ([$short, $long] as $form) {
            $old = $form->submissions()->create(['data' => ['m' => 'old']]);
            $old->forceFill(['created_at' => now()->subDays(30)])->save();
            $form->submissions()->create(['data' => ['m' => 'new']]);
        }

        $this->artisan('submissions:prune')->assertSuccessful();

        $this->assertSame(['new'], $short->submissions()->pluck('data')->pluck('m')->all());
        $this->assertSame(2, $long->submissions()->count());
    }

    public function test_a_form_moved_from_formspark_keeps_its_honeypot_subject_and_reply_to(): void
    {
        Mail::fake();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $form = Form::factory()->create();

        // Formspark's honeypot name catches a bot, in a form post and in JSON.
        $this->post("/f/{$form->public_id}", ['message' => 'Buy now', '_honeypot' => 'x'])->assertRedirect();
        $this->postJson("/f/{$form->public_id}", ['message' => 'Buy now', '_honeypot' => 'x'])->assertJson(['ok' => true]);
        $this->assertSame([true, true], $form->submissions()->pluck('spam')->all());
        Mail::assertNothingQueued();

        // _email.subject and _email.replyto, as JSON sends them and as PHP rewrites them from a form post ("." becomes "_").
        foreach ([['_email.subject', '_email.replyto'], ['_email_subject', '_email_replyto']] as [$subjectKey, $replyKey]) {
            $this->postJson("/f/{$form->public_id}", ['message' => 'Hi', $subjectKey => 'Quote request', $replyKey => 'anna@client.example'])->assertJson(['ok' => true]);
            $submission = $form->submissions()->latest('id')->first();
            $this->assertSame('Quote request', $submission->mailSubject(), $subjectKey);
            $this->assertSame('anna@client.example', $submission->replyToAddress(), $replyKey);
            $this->assertArrayNotHasKey($subjectKey, $submission->data);
        }

        // A mistyped address under Formspark's name is refused like one under ours.
        $this->postJson("/f/{$form->public_id}", ['message' => 'Hi', '_email.replyto' => 'nope'])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'TYPE_EMAIL');

        // Our own name wins when both are sent.
        $this->postJson("/f/{$form->public_id}", ['message' => 'Hi', '_subject' => 'Ours', '_email.subject' => 'Theirs']);
        $this->assertSame('Ours', $form->submissions()->latest('id')->first()->mailSubject());
    }

    public function test_a_form_moved_from_formsubmit_keeps_its_honeypot_and_drops_its_control_fields(): void
    {
        Mail::fake();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $form = Form::factory()->create();

        // FormSubmit's _honey catches a bot, in a form post and in JSON.
        $this->post("/f/{$form->public_id}", ['message' => 'Buy now', '_honey' => 'x'])->assertRedirect();
        $this->postJson("/f/{$form->public_id}", ['message' => 'Buy now', '_honey' => 'x'])->assertJson(['ok' => true]);
        $this->assertSame([true, true], $form->submissions()->pluck('spam')->all());
        Mail::assertNothingQueued();

        // An empty _honey is a person; FormSubmit-only fields are not stored as answers.
        $this->postJson("/f/{$form->public_id}", [
            'message' => 'Hi', '_honey' => '', '_captcha' => 'false', '_template' => 'table', '_cc' => 'b@client.example', '_subject' => 'Quote request',
        ])->assertJson(['ok' => true]);
        $submission = $form->submissions()->latest('id')->first();
        $this->assertFalse((bool) $submission->spam);
        $this->assertSame('Quote request', $submission->mailSubject());
        foreach (['_honey', '_captcha', '_template', '_cc'] as $key) {
            $this->assertArrayNotHasKey($key, $submission->data, $key);
        }
    }

    public function test_formspark_redirect_and_error_fields_follow_the_same_rules_as_next(): void
    {
        Mail::fake();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $form = Form::factory()->create(['site_url' => 'https://client-site.nl']);
        $from = ['Referer' => 'https://client-site.nl/contact/form.html'];

        $this->post("/f/{$form->public_id}", ['message' => 'Hi', '_redirect' => 'https://client-site.nl/bedankt'])
            ->assertRedirect('https://client-site.nl/bedankt');
        // _next wins when a form carries both.
        $this->post("/f/{$form->public_id}", ['message' => 'Hi', '_next' => 'https://client-site.nl/a', '_redirect' => 'https://client-site.nl/b'])
            ->assertRedirect('https://client-site.nl/a');
        // Relative addresses open on the page the form was sent from, else on the saved site.
        $this->post("/f/{$form->public_id}", ['message' => 'Hi', '_redirect' => '/bedankt'], $from)->assertRedirect('https://client-site.nl/bedankt');
        $this->post("/f/{$form->public_id}", ['message' => 'Hi', '_redirect' => 'thanks.html'], $from)->assertRedirect('https://client-site.nl/contact/thanks.html');
        $this->post("/f/{$form->public_id}", ['message' => 'Hi', '_redirect' => '/bedankt'])->assertRedirect('https://client-site.nl/bedankt');
        $this->post("/f/{$form->public_id}", ['message' => 'Hi', '_redirect' => '/bedankt'], ['Referer' => 'https://other-client.nl/'])
            ->assertRedirect('https://other-client.nl/bedankt');

        // A relative path never leaves the sending site, and the old traps still fail.
        foreach (['https://evil.example/', '//evil.example/x', 'javascript:alert(1)', '/\evil.example', "/a\nLocation: https://evil.example", 'https:evil.example'] as $bad) {
            $location = (string) $this->post("/f/{$form->public_id}", ['message' => 'Hi', '_redirect' => $bad], $from)->headers->get('Location');
            $this->assertStringStartsWith(route('submit.thanks'), $location, $bad);
        }
        $plain = Form::factory()->create();
        $this->post("/f/{$plain->public_id}", ['message' => 'Hi', '_redirect' => '/bedankt'])->assertRedirect(route('submit.thanks'));

        // _error takes a refused full-page post to the site's own page; JSON callers still get JSON.
        $this->post("/f/{$form->public_id}", ['email' => 'nope', '_error' => '/oeps'], $from)->assertRedirect('https://client-site.nl/oeps');
        $this->post("/f/{$form->public_id}", ['email' => 'nope', '_error' => 'https://evil.example/'], $from)->assertStatus(422)->assertSee('is not valid');
        $this->postJson("/f/{$form->public_id}", ['email' => 'nope', '_error' => '/oeps'], $from)->assertStatus(422)->assertJson(['ok' => false]);
    }
}
