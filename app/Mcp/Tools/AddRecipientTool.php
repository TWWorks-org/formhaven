<?php

namespace App\Mcp\Tools;

use App\Actions\FormEmails;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[\Laravel\Mcp\Server\Attributes\Title('Add an email recipient')]
#[\Laravel\Mcp\Server\Tools\Annotations\IsDestructive(false)]
#[Name('add_recipient')]
#[Description('Email a form\'s submissions to one more address, usually the site owner\'s client. We email the address a confirmation link, and it gets nothing until that is pressed. Calling it again for an address that has not confirmed sends the link again. Up to 5 addresses per form.')]
class AddRecipientTool extends Tool
{
    public function handle(Request $request, FormEmails $emails): Response
    {
        $form = $request->user()->formsForKey()->where('public_id', (string) $request->get('form_id'))->first();

        if (! $form) {
            return Response::error('No form with this id on the account. Call list_forms to see the ids.');
        }

        $email = strtolower(trim((string) $request->get('email')));
        $existing = $form->recipients()->where('email', $email)->first();

        try {
            if ($existing && ! $existing->isConfirmed()) {
                $emails->resend($existing);

                return Response::json(['recipient' => $existing->toApi(), 'next' => "Sent the confirmation link to {$email} again. Ask them to press it."]);
            }

            $recipient = $emails->add($form, $email);
        } catch (ValidationException $e) {
            return Response::error(collect($e->errors())->flatten()->implode(' '));
        }

        return Response::json([
            'recipient' => $recipient->toApi(),
            'next' => "We emailed {$recipient->email} a confirmation link. Ask them to press it; until then they get nothing. list_forms shows when \"confirmed\" turns true.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'form_id' => $schema->string()->description('The form id from list_forms or create_form.')->required(),
            'email' => $schema->string()->description('The address to send submissions to, e.g. the client\'s inbox.')->required(),
        ];
    }
}
