<x-layouts.site title="Page not found: FormHaven" description="This page does not exist.">
    <section class="max-w-2xl pt-16">
        <h1 class="text-3xl font-semibold">This page does not exist</h1>
        <p class="mt-4 text-zinc-600">The link may be old or mistyped. These pages do exist:</p>
        <ul class="mt-4 grid gap-2">
@if (\App\Support\Mode::hosted())
            <li><a href="{{ route('home') }}" class="underline">Home: how FormHaven works</a></li>
            <li><a href="{{ route('pricing') }}" class="underline">Pricing</a></li>
@endif
            <li><a href="{{ route('login') }}" class="underline">Log in to your forms</a></li>
        </ul>
    </section>
</x-layouts.site>
