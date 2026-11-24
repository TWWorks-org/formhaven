<?php

use App\Models\OAuthClient;
use App\Models\OAuthRefreshToken;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public string $name = '';

    public ?string $newKey = null;

    public function createKey(): void
    {
        $this->validate(['name' => ['required', 'string', 'max:60']]);

        $token = Auth::user()->createToken($this->name);
        $this->newKey = $token->plainTextToken;
        $this->name = '';
        \App\Support\SecurityLog::event('api_key.created', ['user_id' => Auth::id(), 'key_id' => $token->accessToken->id]);
    }

    public function revoke(int $id): void
    {
        if (Auth::user()->tokens()->whereKey($id)->delete()) {
            \App\Support\SecurityLog::event('api_key.deleted', ['user_id' => Auth::id(), 'key_id' => $id]);
        }
    }

    /**
     * Ends a signed-in app: every refresh token it holds for this account, and its access keys.
     * Listed by refresh token, not access key, because the hour-long key expires long before
     * the 60-day refresh token does, and the app must stay visible and revocable all that time.
     */
    public function disconnect(string $clientId): void
    {
        // Revoke first, in one transaction with the token endpoint's own locking, so a refresh that
        // lands mid-way cannot leave a fresh key behind; unused codes are spent so none can be exchanged later.
        \Illuminate\Support\Facades\DB::transaction(function () use ($clientId) {
            $tokens = OAuthRefreshToken::where('user_id', Auth::id())->where('client_id', $clientId)->lockForUpdate();
            (clone $tokens)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            \App\Models\OAuthCode::where('user_id', Auth::id())->where('client_id', $clientId)->whereNull('used_at')->update(['used_at' => now()]);
            Auth::user()->tokens()->whereIn('id', (clone $tokens)->pluck('access_token_id')->filter())->delete();
        });
    }

    public function with(): array
    {
        $active = OAuthRefreshToken::where('user_id', Auth::id())->whereNull('revoked_at')->where('expires_at', '>', now());

        return [
            // Signed-in apps hold short-lived keys that are shown under their connection instead.
            'keys' => Auth::user()->tokens()->latest()->get()->reject(fn ($key) => in_array('mcp:use', $key->abilities ?? [], true))->values(),
            'connections' => (clone $active)->selectRaw('client_id, min(created_at) as first_at, max(created_at) as last_at')->groupBy('client_id')->get()
                ->map(fn ($row) => (object) [
                    'client_id' => $row->client_id,
                    'name' => OAuthClient::find($row->client_id)?->name ?? 'MCP client',
                    'first_at' => \Illuminate\Support\Carbon::parse($row->first_at),
                    'last_at' => \Illuminate\Support\Carbon::parse($row->last_at),
                ]),
        ];
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">API keys</flux:heading>
        <flux:subheading>Let an AI agent or your own code create forms and read submissions. A key you create here has the same access as your login. A key an agent got by starting a form reaches that form only. Apps you signed in to show up here too.</flux:subheading>
    </div>

    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <form wire:submit="createKey" class="flex flex-wrap items-end gap-3 border-b border-zinc-200 p-5 dark:border-zinc-700">
            <div class="min-w-56 flex-1 sm:max-w-md">
                <flux:input wire:model="name" label="New key" placeholder="Claude on my laptop" required />
            </div>
            <flux:button type="submit" variant="primary">Create key</flux:button>
        </form>

        @if ($newKey)
            <div class="border-b border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50">
                <p class="text-sm font-semibold">Copy this key now. It is shown only once.</p>
                <pre class="mt-2 overflow-x-auto rounded-lg bg-white p-3 font-mono text-sm dark:bg-zinc-900"><code>{{ $newKey }}</code></pre>
            </div>
        @endif

        @forelse ($keys as $key)
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 px-5 py-4 text-sm last:border-b-0 dark:border-zinc-800" wire:key="key-{{ $key->id }}">
                <div>
                    <p class="font-semibold">{{ $key->name }}</p>
                    <p class="text-zinc-500">{{ collect($key->abilities)->contains(fn ($a) => str_starts_with($a, 'form:')) ? 'One form only' : 'Full access' }} · Created {{ $key->created_at->toFormattedDateString() }}@if ($key->expires_at) · {{ $key->expires_at->isPast() ? 'expired' : 'expires' }} {{ $key->expires_at->toFormattedDateString() }}@endif · {{ $key->last_used_at ? 'last used '.$key->last_used_at->diffForHumans() : 'never used' }}</p>
                </div>
                <flux:button size="sm" variant="ghost" wire:click="revoke({{ $key->id }})" wire:confirm="Revoke this key? Anything using it stops working.">Revoke</flux:button>
            </div>
        @empty
            <p class="px-5 py-4 text-sm text-zinc-500">No keys yet.</p>
        @endforelse
    </section>

    @if ($connections->isNotEmpty())
        <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading size="md" level="2">Apps you signed in to</flux:heading>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Each one can see and change all your forms through the MCP server until you disconnect it.</p>
            </div>
            @foreach ($connections as $connection)
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 px-5 py-4 text-sm last:border-b-0 dark:border-zinc-800" wire:key="app-{{ $connection->client_id }}">
                    <div>
                        <p class="font-semibold">{{ $connection->name }}</p>
                        <p class="text-zinc-500">Signed in {{ $connection->first_at->toFormattedDateString() }} · last renewed {{ $connection->last_at->diffForHumans() }}</p>
                    </div>
                    <flux:button size="sm" variant="ghost" wire:click="disconnect('{{ $connection->client_id }}')" wire:confirm="Disconnect this app? It has to sign in again to use your forms.">Disconnect</flux:button>
                </div>
            @endforeach
        </section>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="flex min-w-0 flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-5 text-sm dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="md" level="2">Connect an agent (MCP)</flux:heading>
            <p class="text-zinc-600 dark:text-zinc-400">Your agent can create forms, read submissions and choose who gets the emails. Any MCP client that sends a request header connects with your key:</p>
            <pre class="overflow-x-auto rounded-lg bg-zinc-900 p-4 text-zinc-100"><code>URL:    {{ url('/mcp') }}
Header: Authorization: Bearer YOUR_KEY</code></pre>
            <p class="font-medium">Claude Code</p>
            <pre class="overflow-x-auto rounded-lg bg-zinc-900 p-4 text-zinc-100"><code>claude mcp add --transport http formhaven {{ url('/mcp') }} --header "Authorization: Bearer YOUR_KEY"</code></pre>
            <p class="font-medium">Claude (web and desktop)</p>
            <p class="text-zinc-600 dark:text-zinc-400">Customize, Connectors, Add custom connector. Paste the URL above with no header: Claude asks you to sign in to FormHaven and press Allow. The connection then shows up in the list on this page.</p>
            <p class="font-medium">Cursor (mcp.json)</p>
            <pre class="overflow-x-auto rounded-lg bg-zinc-900 p-4 text-zinc-100"><code>{"mcpServers": {"formhaven": {"url": "{{ url('/mcp') }}", "headers": {"Authorization": "Bearer YOUR_KEY"}}}}</code></pre>
        </section>
        <section class="flex min-w-0 flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-5 text-sm dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="md" level="2">REST API</flux:heading>
            <p class="text-zinc-600 dark:text-zinc-400">Send the key as a Bearer token. Errors come back as JSON.</p>
            <pre class="overflow-x-auto rounded-lg bg-zinc-900 p-4 text-zinc-100"><code>GET  {{ url('/api/v1/forms') }}
POST {{ url('/api/v1/forms') }}              {"name": "Contact form on example.nl"}
GET  {{ url('/api/v1/forms/{id}/submissions') }}?spam=0&amp;per_page=25</code></pre>
        </section>
    </div>
</div>
