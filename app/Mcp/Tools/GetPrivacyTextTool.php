<?php

namespace App\Mcp\Tools;

use App\Support\PrivacyText;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[\Laravel\Mcp\Server\Attributes\Title('Get privacy policy text')]
#[Name('get_privacy_text')]
#[Description('Get the paragraph the website\'s privacy policy needs for this form, in English, Dutch or German, filled in from the form\'s real settings: who processes the messages, where they are stored, who sends the emails and how long FormHaven keeps a copy. Also returns a one-line notice to put above the form\'s submit button. Add the paragraph to the site\'s privacy page and tell the site owner to check it; it is not legal advice.')]
#[IsReadOnly]
class GetPrivacyTextTool extends Tool
{
    public function handle(Request $request): Response
    {
        $form = $request->user()->formsForKey()->where('public_id', (string) $request->get('form_id'))->first();

        if (! $form) {
            return Response::error('No form with this id on the account. Call list_forms to see the ids.');
        }

        $language = (string) ($request->get('language') ?? 'en');

        if (! in_array($language, PrivacyText::LANGUAGES, true)) {
            return Response::error('language must be one of: '.implode(', ', PrivacyText::LANGUAGES).'.');
        }

        return Response::json(PrivacyText::for($form, $language));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'form_id' => $schema->string()->description('The form id from list_forms or create_form.')->required(),
            'language' => $schema->string()->enum(PrivacyText::LANGUAGES)->description('en (default), nl or de: the language of the website\'s privacy policy.'),
        ];
    }
}
