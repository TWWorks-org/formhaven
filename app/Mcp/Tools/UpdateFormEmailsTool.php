<?php

namespace App\Mcp\Tools;

use App\Actions\FormEmails;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[\Laravel\Mcp\Server\Attributes\Title('Change email settings')]
#[\Laravel\Mcp\Server\Tools\Annotations\IsDestructive(false)]
#[Name('update_form_emails')]
#[Description('Set the sender name a form\'s recipients see (e.g. the agency\'s name; empty uses the account name), whether the account owner also gets each submission, whether confirmed recipients can read the form\'s messages on a page linked from their emails, and whether they also get a weekly email with last week\'s count and that link. With notify_owner false and no recipient confirmed yet, the owner still gets them, so nothing is lost. Ask the site owner before turning recipients_can_view on: anyone the email is forwarded to can open that link.')]
class UpdateFormEmailsTool extends Tool
{
    public function handle(Request $request, FormEmails $emails): Response
    {
        $form = $request->user()->formsForKey()->where('public_id', (string) $request->get('form_id'))->first();

        if (! $form) {
            return Response::error('No form with this id on the account. Call list_forms to see the ids.');
        }

        try {
            $form = $emails->update($form, Arr::only($request->all(), array_keys(FormEmails::SETTINGS_RULES)));
        } catch (ValidationException $e) {
            return Response::error(collect($e->errors())->flatten()->implode(' '));
        }

        return Response::json(['form' => $form->toApi()]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'form_id' => $schema->string()->description('The form id from list_forms or create_form.')->required(),
            'sender_name' => $schema->string()->description('Shown as who recipients\' emails are from, up to 60 characters, without < > " or backslashes. Empty string resets it to the account name.'),
            'notify_owner' => $schema->boolean()->description('false: only confirmed recipients get submissions. true (default): the account owner gets them too.'),
            'recipients_can_view' => $schema->boolean()->description('true: each confirmed recipient\'s emails carry a link to a read-only page of this form\'s stored messages, newest first, with counts for this month and last month; each recipient\'s link is its "messages_url" in the form. false (default): the link opens a page saying it is not available.'),
            'recipients_weekly_summary' => $schema->boolean()->description('true (default): once a week each confirmed recipient gets a short email with how many messages came in last week, its messages_url and a line asking it to check its spam folder if it missed any. Sent only while recipients_can_view is true, only in a week with at least one message, and not to a recipient whose "weekly_summary_stopped" is true (it pressed stop; its message emails keep coming). false: no weekly email.'),
        ];
    }
}
