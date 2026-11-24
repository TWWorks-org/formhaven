<?php

namespace App\Mcp\Tools;

use App\Actions\CreateForm;
use App\Models\Form;
use App\Support\FormSnippets;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[\Laravel\Mcp\Server\Attributes\Title('Create a form')]
#[\Laravel\Mcp\Server\Tools\Annotations\IsDestructive(false)]
#[Name('create_form')]
#[Description('Create a form and get the endpoint URL to put in the HTML form\'s action attribute. Counts toward the account\'s plan limit on forms.')]
class CreateFormTool extends Tool
{
    public function handle(Request $request, CreateForm $create): Response
    {
        if ($request->user()->keyFormScope() !== null) {
            return Response::error(CreateForm::SCOPED_KEY_REFUSAL);
        }

        try {
            $form = $create->handle($request->user(), Arr::only($request->all(), array_keys(CreateForm::RULES)));
        } catch (ValidationException $e) {
            return Response::error(collect($e->errors())->flatten()->implode(' '));
        }

        return Response::json([
            'form' => $form->toApi(),
            'html' => implode("\n", FormSnippets::html($form->endpoint())),
            'next' => 'Put the html in the site, or call get_form_code for JavaScript, React or Astro. Call get_privacy_text for the paragraph the site\'s privacy policy needs.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Name that tells forms apart, e.g. "Contact form on example.nl".')->required(),
            'site_url' => $schema->string()->description('Optional address of the website the form is on, e.g. "https://example.nl". FormHaven\'s thank-you page links visitors back to it.'),
            'redirect_url' => $schema->string()->description('Optional thank-you page visitors go to after sending.'),
            'retention_days' => $schema->integer()->description('Days to keep submissions, 1 to 365. Default '.Form::DEFAULT_RETENTION_DAYS.'.'),
        ];
    }
}
