<?php

namespace App\Http\Controllers\Api;

use App\Actions\StartForm;
use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormClaim;
use App\Support\FormSnippets;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/** POST /api/v1/start and GET /api/v1/start/{id}: the agent start flow, usable with no account. */
class StartController extends Controller
{
    public function store(Request $request, StartForm $start)
    {
        // A self-hosted install that takes no new accounts reserves forms only for an existing one:
        // a request carrying one of its API keys. Anyone else would get a link nobody can activate.
        if (! \App\Support\Mode::registrationOpen() && ! $request->user('sanctum')) {
            return response()->json([
                'message' => \App\Support\Mode::registrationClosedMessage().' To add a form for the existing account, send its API key as "Authorization: Bearer <key>" here, or POST '.url('/api/v1/forms').' with it.',
                'code' => \App\Support\Mode::REGISTRATION_CLOSED,
                'docs' => url('/llms.txt'),
            ], 403);
        }

        $result = $start->start($request->only(array_keys(StartForm::RULES)), $request->ip());
        $claim = $result['claim'];
        $endpoint = route('submit', $claim->public_id);
        $trial = config('billing.trial_days');
        $owner = \App\Support\Mode::hosted()
            ? 'they create a FormHaven account ('.$trial.'-day trial, no card) or log in, and press Activate. '
            : (\App\Support\Mode::registrationOpen()
                ? 'they create an account on this FormHaven install or log in, and press Activate. '
                : 'they log in to their account on this FormHaven install and press Activate. ');

        return response()->json([
            'data' => [
                'id' => $claim->public_id,
                'endpoint' => $endpoint,
                'status' => 'waiting_for_owner',
                'claim_url' => route('claim.show', $result['claim_token']),
                'setup_key' => $result['setup_key'],
                'expires_at' => $claim->expiresAt()->toIso8601String(),
            ],
            'html' => implode("\n", FormSnippets::html($endpoint)),
            'next' => '1. Put the endpoint in the site\'s form action now. '
                .'2. Ask the site owner to open claim_url: '.$owner
                .'Until then the endpoint answers FORM_NOT_ACTIVE and stores nothing. The link lapses after '.FormClaim::DAYS.' days. '
                .'3. After that, send setup_key as "Authorization: Bearer <setup_key>" on the REST API ('.url('/api/v1').') or the MCP server ('.url('/mcp').') to change settings, get code for other stacks, get the privacy text and read submissions. '
                .'The key works for '.StartForm::setupKeyDays().' days after activation; after that the site owner creates an API key on the API keys page ('.route('api-keys').'). '
                .'GET '.url('/api/v1/start/'.$claim->public_id).' with the same header tells you when it is active.',
        ], 201);
    }

    public function show(Request $request, string $id)
    {
        $claim = FormClaim::where('public_id', $id)->first();
        $key = (string) $request->bearerToken();

        if (! $claim || $key === '' || ! hash_equals($claim->setup_key_hash, hash('sha256', $key))) {
            return response()->json(['message' => 'Unauthenticated. Send the setup_key from POST /api/v1/start as Authorization: Bearer.'], 401);
        }

        if ($claim->isExpired()) {
            return response()->json(['message' => 'This reservation lapsed after '.FormClaim::DAYS.' days without being activated. POST /api/v1/start again.'], 410);
        }

        $active = $claim->isClaimed() && Form::where('public_id', $claim->public_id)->exists();
        // Null while waiting, and for a key made before setup keys expired.
        $expiresAt = $active ? PersonalAccessToken::where('token', $claim->setup_key_hash)->value('expires_at') : null;

        return response()->json(['data' => [
            'id' => $claim->public_id,
            'endpoint' => route('submit', $claim->public_id),
            'status' => $active ? 'active' : 'waiting_for_owner',
            'setup_key_expires_at' => $expiresAt ? \Illuminate\Support\Carbon::parse($expiresAt)->toIso8601String() : null,
        ]]);
    }
}
