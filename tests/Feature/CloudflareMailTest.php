<?php

namespace Tests\Feature;

use App\Mail\DeliveryRefused;
use App\Mail\SubmissionReceived;
use App\Models\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class CloudflareMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'cloudflare',
            'mail.from.address' => 'hello@forms.example.test',
            'mail.from.name' => 'FormHaven',
            'services.cloudflare_email.account_id' => 'acc123',
            'services.cloudflare_email.token' => 'tok',
        ]);
    }

    private function submission()
    {
        $form = Form::factory()->create(['name' => 'Contact']);

        return $form->submissions()->create(['data' => ['email' => 'anna@example.org', 'message' => 'Hoi']]);
    }

    public function test_a_submission_email_goes_out_with_the_visitor_as_reply_to(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => []])]);
        $submission = $this->submission();

        Mail::to($submission->form->user->email)->send(new SubmissionReceived($submission));

        Http::assertSent(function (Request $request) use ($submission) {
            return str_contains($request->url(), '/accounts/acc123/email/sending/send')
                && $request['reply_to'] === 'anna@example.org'
                && $request['to'] === [$submission->form->user->email]
                && $request['from'] === '"Contact via FormHaven" <hello@forms.example.test>'
                && str_contains($request['html'], 'Hoi');
        });
    }

    public function test_a_clients_copy_sends_the_unsubscribe_headers_and_nothing_else(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => []])]);
        $submission = $this->submission();
        $client = $submission->form->recipients()->create(['email' => 'client@bakery.example']);

        Mail::to($client->email)->send(new SubmissionReceived($submission, $client));
        Mail::to('owner@example.org')->send(new SubmissionReceived($submission));

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]);
        $this->assertSame(['List-Unsubscribe' => '<'.$client->stopUrl().'>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'], $sent[0]['headers']);
        $this->assertArrayNotHasKey('headers', $sent[1]->data());
    }

    public function test_an_account_name_cannot_smuggle_an_address_into_the_from_header(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => []])]);
        $submission = $this->submission();
        $submission->form->user->forceFill(['name' => "Evil <boss@bank.example>, \"x\"
Bcc: a@b.example"])->save();
        $client = $submission->form->recipients()->create(['email' => 'client@bakery.example']);

        Mail::to($client->email)->send(new SubmissionReceived($submission->fresh(), $client));

        Http::assertSent(fn (Request $request) => $request['from'] === '"Evil boss@bank.example, x Bcc: a@b.example" <hello@forms.example.test>');
    }

    public function test_a_rejected_send_throws_with_cloudflares_reason(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['code' => 1000, 'message' => 'domain not verified']]])]);
        $submission = $this->submission();

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('domain not verified');

        Mail::to('owner@example.org')->send(new SubmissionReceived($submission));
    }
    /** @return \Throwable|null what the send threw */
    private function sendAndCatch(): ?\Throwable
    {
        try {
            Mail::to('owner@example.org')->send(new SubmissionReceived($this->submission()));
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    public function test_a_permanent_bounce_is_named_bounced(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['delivered' => [], 'permanent_bounces' => ['owner@example.org']]])]);

        $e = $this->sendAndCatch();

        $this->assertInstanceOf(DeliveryRefused::class, $e);
        $this->assertSame('bounced', $e->kind);
        $this->assertStringContainsString('permanently bounced: owner@example.org', $e->getMessage());
    }

    public function test_a_suppressed_address_in_a_success_body_is_named_suppressed(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['suppressed_recipients' => ['owner@example.org']]])]);

        $e = $this->sendAndCatch();

        $this->assertInstanceOf(DeliveryRefused::class, $e);
        $this->assertSame('suppressed', $e->kind);
    }

    public function test_a_400_that_names_suppression_is_named_suppressed_and_other_errors_are_not(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::sequence()
            ->push(['success' => false, 'errors' => [['code' => 10001, 'message' => 'Recipient is on the suppression list']]], 400)
            ->push(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]], 403)]);

        $suppressed = $this->sendAndCatch();
        $this->assertInstanceOf(DeliveryRefused::class, $suppressed);
        $this->assertSame('suppressed', $suppressed->kind);
        $this->assertStringContainsString('HTTP 400', $suppressed->getMessage());

        $other = $this->sendAndCatch();
        $this->assertInstanceOf(TransportException::class, $other);
        $this->assertNotInstanceOf(DeliveryRefused::class, $other, 'A provider outage is not a refused address');
    }
}
