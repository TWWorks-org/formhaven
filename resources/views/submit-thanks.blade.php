<x-visitor-page title="Message sent" :back="$back ?? null">
    <x-slot:icon>
        <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#171717" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m8 12.5 2.5 2.5L16 9.5"/></svg>
    </x-slot:icon>
    <x-slot:heading>Thanks, your message was sent</x-slot:heading>
    <p>{{ isset($back) && $back ? parse_url($back, PHP_URL_HOST).' has it and will reply to the email address you gave.' : 'The site owner has it and will reply to the email address you gave.' }}</p>
</x-visitor-page>
