<?php

use App\Mail\EmailAddressChanged;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    public string $name = '';
    public string $email = '';
    public string $current_password = '';
    public bool $weekly_digest = true;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
        $this->weekly_digest = Auth::user()->weekly_digest;
    }

    /** Saved the moment it is switched, apart from the name and email form. */
    public function updatedWeeklyDigest(bool $on): void
    {
        Auth::user()->forceFill(['weekly_digest' => $on])->save();
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id)
            ],
        ]);

        // The account's email address is where deletion links and password resets go, so a
        // session alone must not be able to change it: that would hand the whole account over.
        // Checked before fill(): the user object is also what the page compares against when it
        // decides to show the password field, so filling first would hide the field and its error.
        $oldEmail = $user->email;
        $changingEmail = $validated['email'] !== $oldEmail;

        if ($changingEmail) {
            if (! $user->has_password) {
                $this->addError('email', 'Your address comes from your Google login. To change it, first set a password with "Forgot password" on the login page; that link goes to your current address.');

                return;
            }

            $this->validate(['current_password' => ['required', 'string', 'current_password']], [
                'current_password.required' => 'Enter your current password to change your email address.',
            ]);

            // The old address is told about the change; without room for that email the change waits.
            if ($refused = \App\Support\AccountMail::allow('email_change', $oldEmail, $user->accountMailPool())) {
                $this->addError('email', $refused);

                return;
            }
        }

        $user->fill($validated);

        if ($changingEmail) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($user->wasChanged('email')) {
            \App\Support\SecurityLog::event('email.changed', ['user_id' => $user->id]);

            if (\App\Support\AccountMail::take('email_change', $user)) {
                Mail::to($oldEmail)->send(new EmailAddressChanged($user, $oldEmail));
            }
        }

        $this->reset('current_password');

        $this->dispatch('profile-updated', name: $user->name);
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        if ($refused = \App\Support\AccountMail::allow('verify', $user->email, $user->accountMailPool())) {
            $this->addError('email', $refused);

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout heading="Profile" subheading="Update your name and email address">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" label="{{ __('Name') }}" type="text" name="name" required autofocus autocomplete="name" />

            <div>
                @if (auth()->user()->has_password)
                    <flux:input wire:model.live.blur="email" label="{{ __('Email') }}" type="email" name="email" required autocomplete="email" />
                @else
                    <flux:input wire:model="email" label="{{ __('Email') }}" type="email" name="email" readonly autocomplete="email" />
                    <p class="mt-2 text-sm text-zinc-500">{{ __('This address comes from your Google login. To change it, first set a password with "Forgot password" on the login page.') }}</p>
                @endif

                @if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail &&! auth()->user()->hasVerifiedEmail())
                    <div>
                        <p class="mt-2 text-sm text-gray-800">
                            {{ __('Your email address is unverified.') }}

                            <button
                                wire:click.prevent="resendVerificationNotification"
                                class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-hidden focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            >
                                {{ __('Click here to re-send the verification email.') }}
                            </button>
                        </p>

                        @if (session('status') === 'verification-link-sent')
                            <p class="mt-2 text-sm font-medium text-green-600">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </p>
                        @endif
                    </div>
                @endif
            </div>

            @if (auth()->user()->has_password && $email !== auth()->user()->email)
                <flux:input wire:model="current_password" label="{{ __('Current password') }}" description="{{ __('Needed to change your email address.') }}" type="password" name="current_password" autocomplete="current-password" />
            @endif

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full">{{ __('Save') }}</flux:button>
                </div>

                <x-action-message class="me-3" on="profile-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>

        <div class="my-6 w-full">
            <flux:switch wire:model.live="weekly_digest" label="Weekly summary email" description="Every Monday: messages per form, anything that was not delivered and forms that went quiet." />
        </div>

        <livewire:settings.delete-user-form />
    </x-settings.layout>
</section>
