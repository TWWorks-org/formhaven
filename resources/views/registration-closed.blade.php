<x-layouts.auth>
    <div class="flex flex-col gap-6">
        <x-auth-header title="Registration is closed on this install" description="This FormHaven install already has its account, so it takes no new sign-ups." />
        <p class="text-center text-sm text-zinc-600 dark:text-zinc-400">Whoever runs it can set ALLOW_REGISTRATION=true in its .env to let others sign up.</p>
        <div class="space-x-1 text-center text-sm text-zinc-600 dark:text-zinc-400">
            Already have an account?
            <x-text-link href="{{ route('login') }}">Log in</x-text-link>
        </div>
    </div>
</x-layouts.auth>
