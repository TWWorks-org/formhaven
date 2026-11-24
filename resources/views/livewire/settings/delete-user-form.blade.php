<?php

use App\Livewire\Actions\Logout;
use App\Mail\AccountDeletionLink;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Component;

new class extends Component {
    public string $password = '';
    public bool $linkSent = false;

    /**
     * Delete the currently authenticated user, after their password. An account made through
     * Google has no password anyone knows: it gets an emailed link instead (sendDeletionLink),
     * so a session someone else got hold of cannot delete it on its own.
     */
    public function deleteUser(Logout $logout): void
    {
        $user = Auth::user();
        abort_unless($user->has_password, 403);

        $this->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        // Stripe first, while the person is still here to read a failure.
        try {
            $user->cancelSubscriptionsNow();
        } catch (\Throwable $e) {
            report($e);
            $this->addError('password', 'Your subscription could not be cancelled, so nothing was deleted. Try again in a minute, or email '.config('legal.email').'.');

            return;
        }

        // Log out before deleting: logging out saves the user's remember token, which would
        // write a deleted user straight back into the database.
        tap($user, $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }

    public function sendDeletionLink(): void
    {
        $user = Auth::user();
        $key = 'account-deletion-link:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('link', 'Three links were sent in the last hour. Use the newest one, or try again later.');

            return;
        }

        if ($refused = \App\Support\AccountMail::allow('deletion_link', $user->email, $user->accountMailPool())) {
            $this->addError('link', $refused);

            return;
        }

        RateLimiter::hit($key, 3600);

        if (! \App\Support\AccountMail::take('deletion_link', $user)) {
            $this->addError('link', 'FormHaven has sent all the account emails it can today. Try again tomorrow, or write to '.config('legal.email').'.');

            return;
        }

        Mail::to($user->email)->send(new AccountDeletionLink($user));
        $this->linkSent = true;
    }
}; ?>

<section class="mt-10 space-y-6">
    <div class="relative mb-5">
        <flux:heading>{{ __('Delete Account') }}</flux:heading>
        <flux:subheading>{{ __('Delete your account and all of its resources') }}</flux:subheading>
    </div>

    <flux:modal.trigger name="confirm-user-deletion">
        <flux:button variant="danger" x-data="" x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')">
            {{ __('Delete Account') }}
        </flux:button>
    </flux:modal.trigger>

    <flux:modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form wire:submit="{{ auth()->user()->has_password ? 'deleteUser' : 'sendDeletionLink' }}" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Are you sure you want to delete your account?') }}</flux:heading>

                <flux:subheading>
                    {{ __('Once your account is deleted, all of its resources and data will be permanently deleted.'.(\App\Support\Mode::hosted() ? ' Any subscription is cancelled straight away.' : '')) }}
                    {{ auth()->user()->has_password ? __('Enter your password to confirm.') : __('You signed up with Google, so we email you a link to confirm.') }}
                </flux:subheading>
            </div>

            @if (auth()->user()->has_password)
                <flux:input wire:model="password" id="password" label="{{ __('Password') }}" type="password" name="password" />
            @elseif ($linkSent)
                <p class="text-sm" role="status">We sent a link to {{ auth()->user()->email }}. Open it within {{ \App\Mail\AccountDeletionLink::MINUTES }} minutes, in this browser, and confirm there.</p>
            @endif
            @error('link')<p class="text-sm text-red-700">{{ $message }}</p>@enderror

            <div class="flex justify-end space-x-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" type="submit">{{ auth()->user()->has_password ? __('Delete Account') : ($linkSent ? __('Send the link again') : __('Email me the link')) }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
