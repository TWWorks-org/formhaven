<?php

namespace App\Mail;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;
use Throwable;

/**
 * Send through Cloudflare Email Service over its REST API.
 *
 * This exists because of a port, not a preference. Cloudflare only accepts
 * SMTP submission on 465 with implicit TLS and says so explicitly: STARTTLS on
 * 587 and relay on 25 are refused. Some hosting providers block outbound 25 and
 * 465 by default on cloud servers and leave 587 open. On such a host the only
 * SMTP port the mail provider offers is the one the host blocks, so this
 * transport sends over the provider's HTTPS API on 443 instead.
 *
 * On a host where 465 is open, this class is not needed: set MAIL_MAILER to smtp and point it at
 * smtp.mx.cloudflare.net:465 with username `api_token`. That is the reason the
 * `smtp` mailer config is left untouched rather than rewritten.
 *
 * Throws TransportException on every failure path on purpose (DeliveryRefused, a subclass,
 * when the provider refused the address itself, so the owner can be told which one). Laravel's queue
 * treats that exactly as it treated the SES one: three attempts, then the job
 * lands in failed_jobs carrying the provider's own error text. That text is
 * what made the dead SES credential diagnosable at all, so the Cloudflare error
 * code and message are put in the exception rather than summarised.
 */
class CloudflareTransport extends AbstractTransport
{
    private const ENDPOINT = 'https://api.cloudflare.com/client/v4/accounts/%s/email/sending/send';

    /**
     * Long enough for a slow API call, short enough that a hung request cannot
     * hold the single queue worker open. The queue owns retrying, so there is
     * deliberately no retry here: one attempt, then throw.
     */
    private const TIMEOUT_SECONDS = 15;

    /** One-click unsubscribe (RFC 8058) on a client's copy; see the REST API page's custom headers example. */
    private const PASSED_HEADERS = ['List-Unsubscribe', 'List-Unsubscribe-Post'];

    public function __construct(
        private readonly string $accountId,
        private readonly string $token,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $from = $email->getFrom()[0] ?? null;

        if ($from === null) {
            throw new TransportException('Cloudflare Email Service: the message has no From address.');
        }

        $recipients = array_map(
            fn (Address $address): string => $address->getAddress(),
            $email->getTo(),
        );

        if ($recipients === []) {
            throw new TransportException('Cloudflare Email Service: the message has no To address.');
        }

        // FormHaven's whole promise is "press Reply to answer the visitor", so
        // the visitor's address must survive the trip. The API takes reply_to
        // as one string (API reference, checked 2026-10-27).
        $replyTo = $email->getReplyTo()[0] ?? null;

        // Only the headers we set on purpose. Symfony adds its own (Message-ID, Date,
        // MIME), which are the provider's job; the API's own example sends these two.
        $headers = [];
        foreach (self::PASSED_HEADERS as $name) {
            if ($header = $email->getHeaders()->get($name)) {
                $headers[$name] = $header->getBodyAsString();
            }
        }

        $payload = array_filter([
            'from' => $this->sender($from),
            'to' => $recipients,
            'reply_to' => $replyTo?->getAddress(),
            'subject' => (string) $email->getSubject(),
            'html' => $this->body($email->getHtmlBody()),
            'text' => $this->body($email->getTextBody()),
            'headers' => $headers ?: null,
        ], fn ($value) => $value !== null);

        try {
            $response = Http::withToken($this->token)
                ->timeout(self::TIMEOUT_SECONDS)
                ->asJson()
                ->post(sprintf(self::ENDPOINT, $this->accountId), $payload);
        } catch (Throwable $e) {
            // A connection-level failure (DNS, TLS, timeout). Wrapped rather
            // than allowed to escape as a Guzzle exception, so the queue and
            // anything catching mail failures sees one exception type.
            throw new TransportException(
                'Cloudflare Email Service: request failed before a response. '.$e->getMessage(),
                0,
                $e,
            );
        }

        if ($response->failed()) {
            $message = sprintf(
                'Cloudflare Email Service returned HTTP %d: %s',
                $response->status(),
                $this->errorText($response->json('errors'), $response->body()),
            );

            // Cloudflare's suppression docs: a suppressed recipient gets an HTTP 400 unless the
            // domain's "Drop suppressed recipients" setting is on, which answers as below instead.
            // Which of the two our domain does was not checked, so both are read.
            throw preg_match('/suppress/i', $message)
                ? new DeliveryRefused($message, DeliveryRefused::SUPPRESSED)
                : new TransportException($message);
        }

        // The API answers 200 with success:false for a rejected message, so the
        // status code alone is not the outcome. Reading only the status is how a
        // send silently stops working while every log line stays green.
        if ($response->json('success') !== true) {
            throw new TransportException(
                'Cloudflare Email Service rejected the message: '
                .$this->errorText($response->json('errors'), $response->body()),
            );
        }

        $bounced = $response->json('result.permanent_bounces') ?: [];

        if ($bounced !== []) {
            throw new DeliveryRefused(
                'Cloudflare Email Service permanently bounced: '.implode(', ', $bounced),
                DeliveryRefused::BOUNCED,
            );
        }

        // Not in the documented schema; found by reading a real response on
        // 2026-09-11. An address on Cloudflare's suppression list (it bounced
        // or complained before) is dropped inside a success:true body with no
        // bounce recorded, so without this a suppressed maker is told nothing
        // and so are we. Retrying will not clear it, but landing in failed_jobs
        // is the only thing that makes it visible at all, which is the whole
        // reason this transport reads the body instead of the status code.
        $suppressed = $response->json('result.suppressed_recipients') ?: [];

        if ($suppressed !== []) {
            throw new DeliveryRefused(
                'Cloudflare Email Service suppressed (previous bounce or complaint): '
                .implode(', ', $suppressed),
                DeliveryRefused::SUPPRESSED,
            );
        }
    }

    /**
     * "Name <address>" when there is a display name, a bare address otherwise.
     *
     * MAIL_FROM_NAME is set, and a From with no display name renders in a
     * client as the raw address, which reads as machine mail.
     */
    private function sender(Address $from): string
    {
        // Quoted, so a display name holding "<" or "," can never be read as a second address.
        $name = addcslashes(preg_replace('/[\r\n]+/', ' ', $from->getName()), '"\\');

        return $name === ''
            ? $from->getAddress()
            : sprintf('"%s" <%s>', $name, $from->getAddress());
    }

    /**
     * Symfony hands a body back as a string or a stream depending on how it was
     * set, and Laravel's rendered mail can be either.
     */
    private function body(mixed $body): ?string
    {
        if ($body === null) {
            return null;
        }

        if (is_resource($body)) {
            $contents = stream_get_contents($body);

            return $contents === false ? null : $contents;
        }

        return (string) $body;
    }

    /**
     * @param  array<int, array{code?: int|string, message?: string}>|null  $errors
     */
    private function errorText(?array $errors, string $fallback): string
    {
        if (empty($errors)) {
            return $fallback === '' ? '(no error body)' : $fallback;
        }

        return implode('; ', array_map(
            fn (array $error): string => trim(sprintf(
                '%s %s',
                $error['code'] ?? '',
                $error['message'] ?? '',
            )),
            $errors,
        ));
    }

    public function __toString(): string
    {
        return 'cloudflare+api://api.cloudflare.com';
    }
}
