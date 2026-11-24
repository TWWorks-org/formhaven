<?php

namespace App\View\Components;

use Illuminate\View\Component;

/**
 * External link with the correct rel attributes baked in.
 *
 *   <x-ext-link href="https://example.com">Editorial link</x-ext-link>
 *   <x-ext-link href="https://example.com" rel="sponsored">Affiliate</x-ext-link>
 *
 * rel policy (rel values are hints to Google; blanket nofollow gives no ranking gain):
 *   - editorial citation you stand behind: no extra rel (dofollow)
 *   - paid / affiliate: rel="sponsored"
 *   - user-generated: rel="ugc"
 *   - competitor / low-trust: rel="nofollow"
 * noopener noreferrer is always added because the link opens a new tab.
 */
class ExtLink extends Component
{
    public function __construct(
        public string $href,
        public ?string $rel = null,
    ) {}

    public function relAttribute(): string
    {
        $parts = ['noopener', 'noreferrer'];

        if ($this->rel) {
            $parts[] = $this->rel;
        }

        return implode(' ', $parts);
    }

    public function render()
    {
        return view('components.ext-link');
    }
}
