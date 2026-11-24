<?php

namespace App\Mcp\Tools;

use App\Actions\FormSpamSettings;
use App\Models\Form;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[\Laravel\Mcp\Server\Attributes\Title('Change spam settings')]
#[\Laravel\Mcp\Server\Tools\Annotations\IsDestructive(false)]
#[Name('update_form_spam')]
#[Description('Turn a form\'s bot check on or off and set its spam words. The bot check is invisible to visitors: the page loads one script (returned as bot_check_script) that solves a small puzzle before the form sends, and posts without it go to spam. A submission containing a spam word goes to spam too. Spam is kept, never emailed.')]
class UpdateFormSpamTool extends Tool
{
    public function handle(Request $request, FormSpamSettings $spam): Response
    {
        $form = $request->user()->formsForKey()->where('public_id', (string) $request->get('form_id'))->first();

        if (! $form) {
            return Response::error('No form with this id on the account. Call list_forms to see the ids.');
        }

        try {
            $form = $spam->update($form, Arr::only($request->all(), array_keys(FormSpamSettings::RULES)));
        } catch (ValidationException $e) {
            return Response::error(collect($e->errors())->flatten()->implode(' '));
        }

        return Response::json(array_filter([
            'form' => $form->toApi(),
            'next' => $form->bot_check ? 'Add '.Form::botCheckScript().' once to every page with this form, in the site\'s HTML. Without it every post goes to spam.' : null,
        ]));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'form_id' => $schema->string()->description('The form id from list_forms or create_form.')->required(),
            'bot_check' => $schema->boolean()->description('true: posts need the bot-check script on the page, else they go to spam. Add the script to the site before turning this on.'),
            'spam_words' => $schema->array()->items($schema->string())->description('Replaces the list: up to 50 words or phrases, 2 to 60 characters each. A submission containing one goes to spam; case and accents are ignored. An empty list clears it.'),
        ];
    }
}
