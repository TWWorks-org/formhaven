<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[\Laravel\Mcp\Server\Tools\Annotations\IsDestructive]
#[\Laravel\Mcp\Server\Attributes\Title('Remove an email recipient')]
#[Name('remove_recipient')]
#[Description('Stop emailing a form\'s submissions to an address added with add_recipient. The account owner keeps getting them if notify_owner is on.')]
class RemoveRecipientTool extends Tool
{
    public function handle(Request $request): Response
    {
        $form = $request->user()->formsForKey()->where('public_id', (string) $request->get('form_id'))->first();

        if (! $form) {
            return Response::error('No form with this id on the account. Call list_forms to see the ids.');
        }

        $email = strtolower(trim((string) $request->get('email')));

        if (! $form->recipients()->where('email', $email)->delete()) {
            return Response::error("This form does not email {$email}. list_forms shows each form's recipients.");
        }

        return Response::json(['removed' => $email, 'next' => "{$email} gets no more submissions from this form."]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'form_id' => $schema->string()->description('The form id from list_forms or create_form.')->required(),
            'email' => $schema->string()->description('The address to stop emailing.')->required(),
        ];
    }
}
