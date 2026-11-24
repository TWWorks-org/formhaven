<?php

namespace App\Mcp\Tools;

use App\Actions\DeleteForm;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[\Laravel\Mcp\Server\Tools\Annotations\IsDestructive]
#[\Laravel\Mcp\Server\Attributes\Title('Delete a form')]
#[Name('delete_form')]
#[Description('Delete a form and every submission stored on it, for a client site that is gone. The bill is per form, so it counts one form fewer from that day. confirm must repeat the form\'s name exactly. Ask the site owner before calling it: the submissions cannot be brought back.')]
class DeleteFormTool extends Tool
{
    /** The attribute above on the hosted service; a self-hosted install has no bill to mention. */
    public function description(): string
    {
        return \App\Support\Mode::hosted()
            ? parent::description()
            : 'Delete a form and every submission stored on it, for a client site that is gone. confirm must repeat the form\'s name exactly. Ask the site owner before calling it: the submissions cannot be brought back.';
    }

    public function handle(Request $request, DeleteForm $delete): Response
    {
        if ($request->user()->keyFormScope() !== null) {
            return Response::error('This key belongs to one form and cannot delete it. Connect with an API key from the dashboard.');
        }

        $form = $request->user()->formsForKey()->where('public_id', (string) $request->get('form_id'))->first();

        if (! $form) {
            return Response::error('No form with this id on the account. Call list_forms to see the ids.');
        }

        $name = $form->name;

        try {
            $submissions = $delete->handle($form, (string) $request->get('confirm'));
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first('confirm'));
        }

        return Response::json(['deleted' => $request->get('form_id'), 'next' => DeleteForm::summary($name, $submissions, $request->user()->isPaying())]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'form_id' => $schema->string()->description('The form id from list_forms.')->required(),
            'confirm' => $schema->string()->description('The form\'s name, exactly as list_forms shows it.')->required(),
        ];
    }
}
