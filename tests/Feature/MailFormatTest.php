<?php

namespace Tests\Feature;

use App\Mail\AbuseReportFiled;
use App\Mail\SubmissionReceived;
use App\Models\AbuseReport;
use App\Models\Form;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** How our emails read: labels above answers, nothing a visitor typed turns into a link, and no stock Laravel wording. */
class MailFormatTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: string, 2: \Symfony\Component\Mime\Email} html, text and the message as sent */
    private function send($mailable): array
    {
        Mail::to('owner@example.org')->sendNow($mailable);
        $message = collect(app('mailer')->getSymfonyTransport()->messages())->last()->getOriginalMessage();

        return [$message->getHtmlBody(), $message->getTextBody(), $message];
    }

    public function test_answers_sit_under_readable_labels_and_empty_fields_fold_into_one_line(): void
    {
        $form = Form::factory()->create(['name' => 'Example Bakery corrections']);
        $submission = $form->submissions()->create(['data' => [
            'email' => 'sanne@example.org', 'gym' => 'Basic-Fit Woensel', 'page' => '', 'first_name' => 'Sanne', 'source' => ' ',
        ]]);

        [$html, $text] = $this->send(new SubmissionReceived($submission));

        $this->assertStringContainsString('>Gym</div>', $html);
        $this->assertStringContainsString('>First name</div>', $html);
        $this->assertStringContainsString('Left empty: Page, Source', $html);
        $this->assertStringNotContainsString('>Page</div>', $html, 'An empty answer gets no row of its own');
        $this->assertStringNotContainsString('>Field<', $html);
        $this->assertStringContainsString("Gym: Basic-Fit Woensel\n", $text);
        $this->assertStringContainsString('Left empty: Page, Source', $text);
    }

    public function test_a_blank_line_in_an_answer_cannot_end_the_block_and_smuggle_a_link(): void
    {
        $form = Form::factory()->create();
        $bait = "Hello\n\n[Verify your account](https://evil.example/login)\n\n![](https://evil.example/pixel.png)";
        $submission = $form->submissions()->create(['data' => ['message' => $bait]]);

        [$html] = $this->send(new SubmissionReceived($submission));

        $this->assertStringNotContainsString('href="https://evil.example', $html);
        $this->assertStringNotContainsString('src="https://evil.example', $html);
        $this->assertStringContainsString('[Verify your account](https://evil.example/login)', $html, 'Shown as typed');
    }

    public function test_an_abuse_report_shows_the_reporters_text_as_typed(): void
    {
        $report = AbuseReport::create(['url' => 'https://forms.example.test/f/abc', 'name' => 'Pat', 'email' => 'pat@example.org',
            'reason' => "Phishing.\n\n[proof](https://evil.example)"]);

        [$html] = $this->send(new AbuseReportFiled($report));

        $this->assertStringNotContainsString('href="https://evil.example', $html);
        $this->assertStringContainsString('Why it is illegal or abusive', $html);
    }

    public function test_the_owners_copy_names_the_form_in_the_subject_and_sender(): void
    {
        $form = Form::factory()->create(['name' => 'Example Bakery corrections']);
        $withSubject = $form->submissions()->create(['data' => ['_subject' => 'Correction', 'message' => 'Hi']]);
        $namesForm = $form->submissions()->create(['data' => ['_subject' => 'New on Example Bakery corrections', 'message' => 'Hi']]);

        [, , $message] = $this->send(new SubmissionReceived($withSubject));
        $this->assertSame('Correction | Example Bakery corrections', $message->getSubject());
        $this->assertSame('Example Bakery corrections via FormHaven', $message->getFrom()[0]->getName());

        [, , $message] = $this->send(new SubmissionReceived($namesForm));
        $this->assertSame('New on Example Bakery corrections', $message->getSubject(), 'The form name is not added twice');
    }

    public function test_a_form_name_cannot_smuggle_an_address_into_the_owners_from_header(): void
    {
        $form = Form::factory()->create(['name' => "Evil <boss@bank.example>, \"x\"\nBcc: a@b.example"]);
        $submission = $form->submissions()->create(['data' => ['message' => 'Hi']]);

        [, , $message] = $this->send(new SubmissionReceived($submission));

        $this->assertCount(1, $message->getFrom());
        $this->assertSame(config('mail.from.address'), $message->getFrom()[0]->getAddress());
        $this->assertStringNotContainsString('<', $message->getFrom()[0]->getName());
        $this->assertEmpty($message->getBcc());
    }

    public function test_the_heading_names_the_sender(): void
    {
        $form = Form::factory()->create(['name' => 'Contact']);
        $named = $form->submissions()->create(['data' => ['name' => 'Jordan Blake', 'email' => 'j@example.org', 'message' => 'Hi']]);
        $anonymous = $form->submissions()->create(['data' => ['message' => 'Hi']]);

        $this->assertStringContainsString('New message from Jordan Blake', $this->send(new SubmissionReceived($named))[0]);
        $this->assertStringContainsString('New message on Contact', $this->send(new SubmissionReceived($anonymous))[0]);
    }

    public function test_account_emails_use_our_wording_not_laravels(): void
    {
        $user = User::factory()->create(['email' => 'robin@example.org']);

        foreach ([new VerifyEmail, new ResetPassword('token123')] as $notification) {
            $mail = $notification->toMail($user);
            $html = (string) $mail->render();

            $this->assertStringNotContainsString('Hello!', $html);
            $this->assertStringNotContainsString('Regards', $html);
            $this->assertStringNotContainsString('All rights reserved', $html);
            $this->assertStringContainsString('robin@example.org', $html);
        }

        $this->assertSame('Reset your FormHaven password', (new ResetPassword('t'))->toMail($user)->subject);
        $this->assertStringContainsString('reset-password/token123', (string) (new ResetPassword('token123'))->toMail($user)->render());
    }
}
