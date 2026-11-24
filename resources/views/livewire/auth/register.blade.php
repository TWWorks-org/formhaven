<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public bool $terms = false;

    /**
     * Handle an incoming registration request.
     */
    public function register(): void
    {
        // The page itself is closed by its route's middleware; this stops a request sent to the component directly.
        if (! \App\Support\Mode::registrationOpen()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['email' => \App\Support\Mode::registrationClosedMessage()]);
        }

        // Only the hosted service has terms to accept (resources/legal).
        $hosted = \App\Support\Mode::hosted();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ] + ($hosted ? ['terms' => ['accepted']] : []), ['terms.accepted' => 'Tick the box to accept the terms before you create an account.']);

        // A new account is one confirmation email: limited per visitor, per address and per day.
        if ($refused = \App\Support\AccountMail::allow('verify', $validated['email'], \App\Support\MailBudget::SIGNUP)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['email' => $refused]);
        }

        $validated['password'] = Hash::make($validated['password']);
        unset($validated['terms']);

        $user = User::create($validated);
        if ($hosted) {
            $user->forceFill(['terms_accepted_at' => now()])->save();
        }

        event(new Registered($user));

        Auth::login($user);

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header title="Create an account" description="Enter your details below to create your account" />

    <!-- Session Status -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <x-google-button />

    <form wire:submit="register" class="flex flex-col gap-6">
        <!-- Name -->
        <div class="grid gap-2">
            <flux:input wire:model="name" id="name" label="{{ __('Name') }}" type="text" name="name" required autofocus autocomplete="name" placeholder="Full name" />
        </div>

        <!-- Email Address -->
        <div class="grid gap-2">
            <flux:input wire:model="email" id="email" label="{{ __('Email address') }}" type="email" name="email" required autocomplete="email" placeholder="email@example.com" />
        </div>

        <!-- Password -->
        <div class="grid gap-2">
            <flux:input
                wire:model="password"
                id="password"
                label="{{ __('Password') }}"
                type="password"
                name="password"
                required
                autocomplete="new-password"
                placeholder="Password"
            />
        </div>

        <!-- Confirm Password -->
        <div class="grid gap-2">
            <flux:input
                wire:model="password_confirmation"
                id="password_confirmation"
                label="{{ __('Confirm password') }}"
                type="password"
                name="password_confirmation"
                required
                autocomplete="new-password"
                placeholder="Confirm password"
            />
        </div>

        @includeWhen(\App\Support\Mode::hosted(), 'partials.register-terms'){{-- Only the hosted service has terms to accept. Included, not wrapped in an if block: Livewire adds morph-marker comments around those inside components. --}}

        <div class="flex items-center justify-end">
            <flux:button type="submit" variant="primary" class="w-full">
                {{ __('Create account') }}
            </flux:button>
        </div>
    </form>

    <div class="space-x-1 text-center text-sm text-zinc-600 dark:text-zinc-400">
        Already have an account?
        <x-text-link href="{{ route('login') }}">Log in</x-text-link>
    </div>
</div>
