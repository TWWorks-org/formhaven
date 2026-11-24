<x-mail::message>
# Delete your FormHaven account?

Someone logged in to your account asked to delete it. If that was you, open this link while you are logged in and confirm. It works for {{ $minutes }} minutes.

<x-mail::button :url="$url">
Delete my account
</x-mail::button>

Deleting removes your forms and every submission straight away{{ \App\Support\Mode::hosted() ? ' and cancels any subscription' : '' }}. If you did not ask for this, ignore this email: nothing happens, and you may want to sign out of FormHaven on other devices.
</x-mail::message>
