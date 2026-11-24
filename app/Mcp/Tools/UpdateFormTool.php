<?php

namespace App\Mcp\Tools;

use App\Actions\FormSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[\Laravel\Mcp\Server\Attributes\Title('Change form settings')]
#[\Laravel\Mcp\Server\Tools\Annotations\IsDestructive(true)]
#[Name('update_form')]
#[Description('Change a form\'s name, website, thank-you page, how long submissions are kept (or keep none), its webhook, the sites allowed to post to it and its DidMyFormSend key. Send only the fields to change. Setting a webhook returns the signing secret for the FormHaven-Signature header. The key is never returned: the form shows monitor.on and monitor.key_prefix.')]
class UpdateFormTool extends Tool
{
    public function handle(Request $request, FormSettings $settings): Response
    {
        $form = $request->user()->formsForKey()->where('public_id', (string) $request->get('form_id'))->first();

        if (! $form) {
            return Response::error('No form with this id on the account. Call list_forms to see the ids.');
        }

        try {
            $form = $settings->update($form, Arr::only($request->all(), array_keys(FormSettings::RULES)));
        } catch (ValidationException $e) {
            return Response::error(collect($e->errors())->flatten()->implode(' '));
        }

        $webhookSet = filled($request->get('webhook_url'));

        return Response::json(array_filter([
            'form' => $form->toApi(),
            'webhook_signing_secret' => $webhookSet ? $form->webhook_secret : null,
            'next' => $webhookSet ? 'Verify the FormHaven-Signature header of each webhook with webhook_signing_secret: v1 is the hex HMAC-SHA256 of "<t>.<raw body>".' : null,
        ]));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'form_id' => $schema->string()->description('The form id from list_forms or create_form.')->required(),
            'name' => $schema->string()->description('Name that tells forms apart, up to 100 characters.'),
            'site_url' => $schema->string()->description('Address of the website the form is on. FormHaven\'s thank-you page links back to it. Empty string clears it.'),
            'redirect_url' => $schema->string()->description('Thank-you page visitors go to after sending. Empty string clears it.'),
            'retention_days' => $schema->integer()->description('Days to keep submissions, 1 to 365.'),
            'store_submissions' => $schema->boolean()->description('false: each submission is emailed and its answers are then deleted. true (default): kept for retention_days.'),
            'webhook_url' => $schema->string()->description('A public https:// URL that gets each non-spam submission as a signed JSON POST. Empty string turns the webhook off.'),
            'monitor_key' => $schema->string()->description('The site owner\'s DidMyFormSend heartbeat key (fmh_ and 40 letters and digits), from their monitor on didmyformsend.com. With it, after each submission FormHaven tells didmyformsend.com whether its emails went out (the mail service\'s name, the failure kind, the time, and each notification address as its first character and domain plus a SHA-256 hash), and the owner gets an alert when they stop. Nothing a visitor wrote is sent. Empty string removes it. Set it only with the owner\'s agreement.'),
            'allowed_domains' => $schema->array()->items($schema->string())->description('Replaces the list: up to 20 domains such as "example.nl". A post from any other site goes to spam. A domain covers its subdomains; localhost always passes. An empty list allows any site.'),
        ];
    }
}
