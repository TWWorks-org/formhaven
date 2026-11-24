<?php

namespace App\Mcp\Tools;

use App\Support\FormSnippets;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[\Laravel\Mcp\Server\Attributes\Title('Get form code')]
#[Name('get_form_code')]
#[Description('Get ready-to-paste code for a form: plain HTML, a JavaScript fetch call, a React component or an Astro component, with the form\'s real address and the hidden spam trap filled in. Includes the bot-check script line when the form has the bot check on.')]
#[IsReadOnly]
class GetFormCodeTool extends Tool
{
    public const STACKS = ['html', 'js', 'react', 'astro'];

    public function handle(Request $request): Response
    {
        $form = $request->user()->formsForKey()->where('public_id', (string) $request->get('form_id'))->first();

        if (! $form) {
            return Response::error('No form with this id on the account. Call list_forms to see the ids.');
        }

        $stack = $request->get('stack');
        if ($stack !== null && ! in_array($stack, self::STACKS, true)) {
            return Response::error('stack must be one of: '.implode(', ', self::STACKS).'. Leave it out to get all of them.');
        }

        $snippets = collect(FormSnippets::for($form, withPrompt: false))
            ->only($stack ? [$stack] : self::STACKS)
            ->map(fn ($s) => ['label' => $s[0], 'code' => $s[1], 'hint' => $s[2]]);

        return Response::json(['form_id' => $form->public_id, 'endpoint' => $form->endpoint(), 'code' => $snippets->all()]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'form_id' => $schema->string()->description('The form id from list_forms or create_form.')->required(),
            'stack' => $schema->string()->enum(self::STACKS)->description('Which code to return. Leave it out to get all of them.'),
        ];
    }
}
