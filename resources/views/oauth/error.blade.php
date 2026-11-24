<x-layouts.auth>
    <x-auth-header title="This sign-in did not work" :description="$message" />
    <a href="{{ route('home') }}" class="text-center text-sm underline">Go to FormHaven</a>
</x-layouts.auth>
