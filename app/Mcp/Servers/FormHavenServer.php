<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddRecipientTool;
use App\Mcp\Tools\CreateFormTool;
use App\Mcp\Tools\DeleteFormTool;
use App\Mcp\Tools\GetFormCodeTool;
use App\Mcp\Tools\GetPrivacyTextTool;
use App\Mcp\Tools\ListFormsTool;
use App\Mcp\Tools\ListSubmissionsTool;
use App\Mcp\Tools\RemoveRecipientTool;
use App\Mcp\Tools\RetryDeliveriesTool;
use App\Mcp\Tools\UpdateFormEmailsTool;
use App\Mcp\Tools\UpdateFormSpamTool;
use App\Mcp\Tools\UpdateFormTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('FormHaven')]
#[Version('1.2.0')]
class FormHavenServer extends Server
{
    /**
     * What an agent reads first. Built per install: where messages are stored comes from config/legal.php
     * (App\Support\Operator), and only the hosted service has a bill. laravel/mcp reads this property when
     * there is no Instructions attribute, so the attribute is gone and createContext() fills it.
     */
    public static function instructions(): string
    {
        $country = \App\Support\Operator::storageCountry();

        return 'FormHaven is '.(\App\Support\Operator::storedInEu() ? 'an EU form backend' : 'a form backend')
            .': each form gets an endpoint URL that an HTML form posts to; submissions are stored '.($country ? 'in '.$country : 'on this install')
            .', spam filtered and emailed to the account owner.'
            .' The key this server was connected with is an API key from the dashboard, or the setup_key from POST /api/v1/start once the site owner activated that form, until it expires (an expired key gets 401 with code SETUP_KEY_EXPIRED: ask the site owner for an API key from the API keys page of the dashboard). To make a website\'s contact form work: call create_form (or use the form you started), then set the form\'s action to the returned endpoint with method POST, give every input a name, and add a hidden input named _gotcha (bots fill it in, those submissions go to spam). For React, Astro or a fetch call, call get_form_code. Call get_privacy_text for the paragraph the site\'s privacy policy needs, in English, Dutch or German, and give it to the site owner to check. Call update_form to change the name, website, thank-you page, retention (or keep none), webhook, allowed sites or DidMyFormSend key. With the site owner\'s DidMyFormSend key saved as monitor_key, FormHaven tells didmyformsend.com after each submission whether its emails went out (never anything a visitor wrote), and the owner gets an alert when they stop; the key is never returned, only monitor.key_prefix. From JavaScript, POST JSON with Accept: application/json and you get {"ok": true}, or {"ok": false, "errors": [{"code", "field", "message"}]} as on Formspree. Optional fields: email or _replyto (the owner replies to it), _subject (email subject), _next (thank-you page on the same site). Use list_submissions to read what visitors sent. To send a form\'s submissions to the site owner\'s client as well, call add_recipient with their address: they confirm by a link we email them, and their copy carries the sender name set with update_form_emails and no FormHaven branding. With the site owner\'s agreement, update_form_emails recipients_can_view true also gives each confirmed recipient a read-only page of the form\'s messages, linked from their emails (each recipient\'s messages_url), and a weekly email with last week\'s count and that link (recipients_weekly_summary, on unless set false). If a form gets spam, call update_form_spam: bot_check true needs the returned script line added to the site\'s pages, and spam_words sends matching messages to spam. Every form in list_forms has a health block: when its status is not ok, tell the site owner what each problem says and what its fix is. Messages that did not arrive stay saved; once the address works again, call retry_deliveries.'
            .(\App\Support\Mode::hosted()
                ? ' Billing is per form: each form adds one unit to the owner\'s monthly bill (never fewer than five units), so create a form only for a site that needs one, and reuse an existing form where you can.'
                : ' Create a form only for a site that needs one, and reuse an existing form where you can.')
            .' For a site that is gone, delete_form removes the form and its submissions after the owner agrees.';
    }

    public function createContext(): \Laravel\Mcp\Server\ServerContext
    {
        $this->instructions = self::instructions();

        return parent::createContext();
    }

    /**
     * The registered tools' names, for the pages and llms.txt that list them, so a new tool
     * cannot be added without the copy following.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public static function toolNames(): \Illuminate\Support\Collection
    {
        return collect(self::tools())->keys();
    }

    /** @return array<string, string> tool name => its description, as the agent reads them */
    public static function tools(): array
    {
        return collect((new \ReflectionClass(static::class))->getDefaultProperties()['tools'])
            ->mapWithKeys(function (string $tool) {
                $class = new \ReflectionClass($tool);
                $attribute = fn (string $name) => $class->getAttributes($name)[0]->getArguments()[0];

                return [$attribute(Name::class) => $attribute(\Laravel\Mcp\Server\Attributes\Description::class)];
            })->all();
    }

    protected array $tools = [
        CreateFormTool::class,
        UpdateFormTool::class,
        GetFormCodeTool::class,
        GetPrivacyTextTool::class,
        ListFormsTool::class,
        ListSubmissionsTool::class,
        AddRecipientTool::class,
        RemoveRecipientTool::class,
        UpdateFormEmailsTool::class,
        UpdateFormSpamTool::class,
        RetryDeliveriesTool::class,
        DeleteFormTool::class,
    ];
}
