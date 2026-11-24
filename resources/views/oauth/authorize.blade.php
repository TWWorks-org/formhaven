<x-layouts.auth>
    <x-auth-header title="Connect {{ $client->name }} to FormHaven" description="Signed in as {{ auth()->user()->email }}" />

    <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-5 text-sm dark:border-zinc-700">
        <p>The app that calls itself <span class="font-semibold">{{ $client->name }}</span> wants to use your FormHaven account through the MCP server. If you allow it, it can:</p>
        <ul class="list-disc space-y-1 pl-5">
            <li>see and change all your forms and their settings</li>
            <li>read the submissions your forms received</li>
            <li>create forms and choose who gets their emails</li>
        </ul>
        <p>After you allow it, you go back to <span class="font-mono break-all">{{ $redirectHost }}</span>.</p>
        @if ($unknownHost)
            <div class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100" role="alert" data-unknown-client>
                <p class="font-semibold">We do not know this app.</p>
                <p class="mt-1">Any app can call itself "{{ $client->name }}". If you press Allow, <span class="font-mono break-all">{{ $redirectHost }}</span> can read and change every form on this account and read all their messages. Only allow it if you started this connection yourself, from an app you trust, and that app is at {{ $redirectHost }}.</p>
            </div>
        @endif
        @if ($loopback)
            <p class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">This sends you back to a program on this computer. Only allow it if you just started connecting from an app such as Claude Code.</p>
        @endif
        <p class="text-zinc-600 dark:text-zinc-400">You can end the connection any time on the <a href="{{ route('api-keys') }}" class="underline">API keys</a> page.</p>
    </div>

    <form method="POST" action="{{ route('oauth.approve') }}" class="flex gap-3">
        @csrf
        @foreach ($params as $name => $value)
            @if (is_string($value))
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endif
        @endforeach
        <flux:button type="submit" name="decision" value="deny" class="flex-1">Deny</flux:button>
        <flux:button type="submit" name="decision" value="allow" variant="primary" class="flex-1">Allow</flux:button>
    </form>
</x-layouts.auth>
