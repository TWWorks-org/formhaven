<x-visitor-page title="Message not sent" :back="$back ?? null" back-label="Back to">
    <x-slot:heading>Your message was not sent</x-slot:heading>
    <p>{{ $message }}</p>
</x-visitor-page>
