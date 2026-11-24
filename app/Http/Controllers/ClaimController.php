<?php

namespace App\Http\Controllers;

use App\Actions\StartForm;
use App\Models\FormClaim;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** /claim/{token}: the site owner switches on a form an agent reserved. */
class ClaimController extends Controller
{
    public function show(Request $request, string $token)
    {
        if (! $claim = $this->find($token)) {
            return $this->expired();
        }

        if ($claim->isClaimed()) {
            return $this->alreadyClaimed($request, $claim);
        }

        // A guest who registers, logs in or uses Google comes straight back here, and so does an
        // account that still has to confirm its address: the link in that email returns here.
        if (! $request->user()?->hasVerifiedEmail()) {
            redirect()->setIntendedUrl($request->fullUrl());
        }

        return view('claim', ['claim' => $claim, 'token' => $token]);
    }

    public function store(Request $request, string $token, StartForm $start)
    {
        if (! $claim = $this->find($token)) {
            return $this->expired();
        }

        if ($claim->isClaimed()) {
            return $this->alreadyClaimed($request, $claim);
        }

        try {
            $form = $start->claim($claim, $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors(['claim' => collect($e->errors())->flatten()->first()]);
        }

        return redirect()->route('forms.show', $form)->with('status', 'Your form is active. Messages sent to it are stored and emailed to you.');
    }

    private function find(string $token): ?FormClaim
    {
        $claim = FormClaim::findByClaimToken($token);

        return $claim && ! $claim->isExpired() ? $claim : null;
    }

    private function expired()
    {
        return response()->view('claim', ['claim' => null, 'token' => null, 'expired' => true], 404);
    }

    private function alreadyClaimed(Request $request, FormClaim $claim)
    {
        $form = $claim->user_id && $request->user()?->id === $claim->user_id
            ? $request->user()->forms()->where('public_id', $claim->public_id)->first()
            : null;

        if ($form && ! $request->user()->hasVerifiedEmail()) {
            return response()->view('claim', ['claim' => $claim, 'token' => null, 'activated' => true]);
        }

        return $form
            ? redirect()->route('forms.show', $form)
            : response()->view('claim', ['claim' => $claim, 'token' => null, 'taken' => true]);
    }
}
