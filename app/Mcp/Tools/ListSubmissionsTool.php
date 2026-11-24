<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[\Laravel\Mcp\Server\Tools\Annotations\IsReadOnly]
#[\Laravel\Mcp\Server\Attributes\Title('Read submissions')]
#[Name('list_submissions')]
#[Description('Read the newest submissions of one form, newest first. Set spam to true to read what the spam filter caught instead.')]
class ListSubmissionsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $form = $request->user()->formsForKey()->where('public_id', (string) $request->get('form_id'))->first();

        if (! $form) {
            return Response::error('No form with this id on the account. Call list_forms to see the ids.');
        }

        $limit = min(100, max(1, (int) $request->get('limit', 20)));
        $query = $form->submissions()->where('spam', (bool) $request->get('spam', false));

        return Response::json([
            'form' => $form->name,
            'total' => (clone $query)->count(),
            'submissions' => $query->with('deliveryFailures')->latest()->limit($limit)->get()->map->toApi(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'form_id' => $schema->string()->description('The form id from list_forms or create_form.')->required(),
            'limit' => $schema->integer()->description('How many to return, 1 to 100. Default 20.'),
            'spam' => $schema->boolean()->description('true to read caught spam instead of real submissions.'),
        ];
    }
}
