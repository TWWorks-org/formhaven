<?php

namespace Tests\Feature;

use App\Mail\SubmissionReceived;
use App\Mcp\Servers\FormHavenServer;
use App\Mcp\Tools\UpdateFormSpamTool;
use App\Models\Form;
use App\Support\BotCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SpamProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /** Solves a puzzle from the real endpoint the way bot-check.js does, and returns the hidden field's value. */
    private function solve(Form $form): string
    {
        $puzzle = $this->getJson("/f/{$form->public_id}/challenge")->assertOk()->json();

        for ($n = 0; $n <= $puzzle['maxnumber']; $n++) {
            if (hash('sha256', $puzzle['salt'].$n) === $puzzle['challenge']) {
                return base64_encode(json_encode(['algorithm' => $puzzle['algorithm'], 'challenge' => $puzzle['challenge'], 'number' => $n, 'salt' => $puzzle['salt'], 'signature' => $puzzle['signature']]));
            }
        }

        $this->fail('The puzzle has no answer within maxnumber.');
    }

    /** Posts and returns the stored row's spam reason: null means it reached the inbox. */
    private function verdict(Form $form, array $fields): ?string
    {
        $this->postJson("/f/{$form->public_id}", $fields)->assertOk()->assertJson(['ok' => true]);

        return $form->submissions()->latest('id')->first()->spam_reason;
    }

    public function test_link_spam_goes_to_spam_and_an_ordinary_message_with_links_does_not(): void
    {
        $form = Form::factory()->create();

        $this->assertSame('link_markup', $this->verdict($form, ['message' => 'Great site [url=https://pills.example]cheap[/url]']));
        $this->assertSame('link_markup', $this->verdict($form, ['message' => 'Visit <A HREF="https://x.example">here</a>']));
        $this->assertSame('links', $this->verdict($form, ['message' => implode(' ', array_map(fn ($i) => "https://seo{$i}.example", range(1, 5)))]));
        // The boundary and the ordinary cases a real enquiry has: they must still arrive.
        $this->assertNull($this->verdict($form, ['message' => implode(' ', array_map(fn ($i) => "https://site{$i}.example", range(1, 4)))]));
        $this->assertNull($this->verdict($form, ['message' => 'Our shop is at https://bakkerij.nl, can you help?', 'website' => 'https://bakkerij.nl']));
        $this->assertNull($this->verdict($form, ['message' => 'I saw your <b>offer</b>, and the [price] list.']));

        Mail::assertQueuedCount(3);
        // Link spam on a form that stores nothing is still kept, so the owner can find a real visitor in it.
        $quiet = Form::factory()->create(['store_submissions' => false]);
        $this->verdict($quiet, ['message' => '[url=https://x.example]x[/url]']);
        $this->assertNotEmpty($quiet->submissions()->sole()->data);
    }

    public function test_spam_words_match_ignoring_case_and_accents_and_leave_other_messages_alone(): void
    {
        $form = Form::factory()->create(['spam_words' => ['seo services', 'crypto']]);

        $this->assertSame('spam_word:seo services', $this->verdict($form, ['message' => 'We offer SEO  Services cheap'.' ']));
        $this->assertSame('spam_word:crypto', $this->verdict($form, ['company' => 'Cryptocoin BV', 'message' => 'Hi']));
        $this->assertNull($this->verdict($form, ['message' => 'Can you quote a website for our café?']));

        $accented = Form::factory()->create(['spam_words' => ['café']]);
        $this->assertSame('spam_word:café', $this->verdict($accented, ['message' => 'CAFE deals']));
        Mail::assertQueuedCount(1);
    }

    public function test_without_the_bot_check_a_post_needs_no_puzzle(): void
    {
        $form = Form::factory()->create();

        $this->assertNull($this->verdict($form, ['message' => 'Hi']));
    }

    public function test_with_the_bot_check_only_a_fresh_solved_puzzle_for_this_form_reaches_the_inbox(): void
    {
        $form = Form::factory()->create(['bot_check' => true]);
        $other = Form::factory()->create(['bot_check' => true]);

        $this->assertSame('bot_check', $this->verdict($form, ['message' => 'No script on the page']));
        $this->assertSame('bot_check', $this->verdict($form, ['message' => 'Garbage', BotCheck::FIELD => 'not-base64!']));

        $answer = $this->solve($form);
        $this->assertSame('bot_check', $this->verdict($other, ['message' => 'Puzzle from another form', BotCheck::FIELD => $answer]));
        $this->assertNull($this->verdict($form, ['message' => 'A real visitor', BotCheck::FIELD => $answer]));
        $this->assertArrayNotHasKey(BotCheck::FIELD, $form->submissions()->latest('id')->first()->data);
        // Replaying the same solution is how a bot would skip the work.
        $this->assertSame('bot_check', $this->verdict($form, ['message' => 'Replay', BotCheck::FIELD => $answer]));

        // Claiming is one atomic step, so of two parallel posts with one fresh solution only one gets through.
        $fresh = $this->solve($form);
        $this->assertTrue(BotCheck::claim($form, $fresh));
        $this->assertFalse(BotCheck::claim($form, $fresh));

        // A forged answer: the right shape, a number that does not hash to the challenge.
        $forged = json_decode(base64_decode($this->solve($form)), true);
        $forged['number'] = ($forged['number'] + 1) % (BotCheck::MAX_NUMBER + 1);
        $this->assertSame('bot_check', $this->verdict($form, ['message' => 'Forged', BotCheck::FIELD => base64_encode(json_encode($forged))]));

        // A puzzle whose signature was not made by us.
        $unsigned = json_decode(base64_decode($this->solve($form)), true);
        $unsigned['signature'] = str_repeat('0', 64);
        $this->assertSame('bot_check', $this->verdict($form, ['message' => 'Unsigned', BotCheck::FIELD => base64_encode(json_encode($unsigned))]));

        // An expired one.
        $old = $this->solve($form);
        $this->travel(BotCheck::TTL_SECONDS + 5)->seconds();
        $this->assertSame('bot_check', $this->verdict($form, ['message' => 'Left open overnight', BotCheck::FIELD => $old]));

        Mail::assertQueuedCount(1);
    }

    public function test_a_visitor_sent_back_to_fix_their_email_keeps_a_working_puzzle(): void
    {
        $form = Form::factory()->create(['bot_check' => true]);
        $answer = $this->solve($form);

        $this->post("/f/{$form->public_id}", ['email' => 'anna@', 'message' => 'Hi', BotCheck::FIELD => $answer])->assertStatus(422);
        $this->assertNull($this->verdict($form, ['email' => 'anna@client.example', 'message' => 'Hi', BotCheck::FIELD => $answer]));
    }

    public function test_the_dashboard_test_button_passes_with_the_bot_check_on(): void
    {
        $form = Form::factory()->create(['bot_check' => true, 'spam_words' => ['test']]);
        $this->actingAs($form->user);

        Volt::test('forms.show', ['form' => $form])->call('sendTest');

        $this->assertFalse($form->submissions()->sole()->spam);
        Mail::assertQueued(SubmissionReceived::class);
    }

    public function test_the_puzzle_endpoint_is_cookieless_and_open_to_any_site(): void
    {
        $form = Form::factory()->create();

        $response = $this->getJson("/f/{$form->public_id}/challenge", ['Origin' => 'https://client-site.nl'])
            ->assertOk()
            ->assertJsonStructure(['algorithm', 'challenge', 'maxnumber', 'salt', 'signature'])
            ->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertSame([], $response->headers->getCookies());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->getJson('/f/doesnotexist/challenge')->assertNotFound()->assertJsonStructure(['message']);
    }

    public function test_the_owner_turns_it_on_in_settings_and_the_setup_code_carries_the_script(): void
    {
        $form = Form::factory()->create();
        $this->actingAs($form->user);
        $script = Form::botCheckScript();

        $setupCode = fn () => collect(Volt::test('forms.show', ['form' => $form->fresh()])->viewData('snippets'))->map(fn ($s) => $s[1]);

        $this->assertFalse($setupCode()->contains(fn ($code) => str_contains($code, $script)));

        Volt::test('forms.show', ['form' => $form])
            ->set('bot_check', true)
            ->set('spam_words', "SEO Services\n seo services , Crypto\n\n")
            ->call('saveSettings')
            ->assertHasNoErrors()
            ->assertSet('spam_words', "seo services\ncrypto");

        $form->refresh();
        $this->assertTrue($form->bot_check);
        $this->assertSame(['seo services', 'crypto'], $form->spam_words);
        // The HTML, Astro and AI-prompt code carry the script line; the JavaScript one runs on a page that has it.
        $this->assertSame(['html', 'astro', 'ai'], $setupCode()->filter(fn ($code) => str_contains($code, $script))->keys()->all());

        Volt::test('forms.show', ['form' => $form])->set('spam_words', 'x')->call('saveSettings')->assertHasErrors('spam_words');
        Volt::test('forms.show', ['form' => $form])->set('spam_words', implode("\n", array_map(fn ($i) => "word{$i}", range(1, 51))))
            ->call('saveSettings')->assertHasErrors('spam_words');
        $this->assertSame(['seo services', 'crypto'], $form->fresh()->spam_words);
    }

    public function test_the_api_and_mcp_change_the_same_settings(): void
    {
        $form = Form::factory()->create();
        $headers = ['Authorization' => 'Bearer '.$form->user->createToken('test')->plainTextToken];
        $url = "/api/v1/forms/{$form->public_id}";

        $this->patchJson($url, ['bot_check' => true, 'spam_words' => ['Casino', 'casino']], $headers)
            ->assertOk()
            ->assertJsonPath('data.bot_check', true)
            ->assertJsonPath('data.spam_words', ['casino'])
            ->assertJsonPath('data.bot_check_script', Form::botCheckScript())
            ->assertJsonPath('next', 'Add '.Form::botCheckScript().' once to every page with this form; without it every post goes to spam.');

        // A bad word refuses the whole change, the valid part included.
        $this->patchJson($url, ['sender_name' => 'Studio', 'spam_words' => ['x']], $headers)->assertUnprocessable()->assertJsonValidationErrors('spam_words');
        $this->assertNull($form->fresh()->sender_name);

        FormHavenServer::actingAs($form->user)->tool(UpdateFormSpamTool::class, ['form_id' => $form->public_id, 'bot_check' => false, 'spam_words' => []])
            ->assertOk();
        $this->assertFalse($form->fresh()->bot_check);
        $this->assertNull($form->fresh()->spam_words);

        FormHavenServer::actingAs($form->user)->tool(UpdateFormSpamTool::class, ['form_id' => 'nope', 'bot_check' => true])->assertHasErrors();
    }
}
