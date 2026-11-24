<?php

namespace App\Mcp\Tools;

use App\Actions\RetryDeliveries;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[\Laravel\Mcp\Server\Attributes\Title('Send undelivered messages again')]
#[\Laravel\Mcp\Server\Tools\Annotations\IsDestructive(false)]
#[Name('retry_deliveries')]
#[Description('Email a form\'s messages that did not arrive once more, after the site owner fixed the address. list_forms shows them under health as UNDELIVERED. Up to 50 per call and 3 calls an hour per form.')]
class RetryDeliveriesTool extends Tool
{
    public function handle(Request $request, RetryDeliveries $retry): Response
    {
        $form = $request->user()->formsForKey()->where('public_id', (string) $request->get('form_id'))->first();

        if (! $form) {
            return Response::error('No form with this id on the account. Call list_forms to see the ids.');
        }

        try {
            $result = $retry->handle($form);
        } catch (ValidationException $e) {
            return Response::error(collect($e->errors())->flatten()->implode(' '));
        }

        return Response::json([...$result, 'next' => RetryDeliveries::summary($result).($result['queued'] ? ' Call list_forms a few minutes later: health shows whatever still did not arrive.' : '')]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'form_id' => $schema->string()->description('The form id from list_forms or create_form.')->required(),
        ];
    }
}
