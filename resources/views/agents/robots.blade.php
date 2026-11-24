{{-- A self-hosted install is a private tool: nothing on it is for search engines. --}}@if (\App\Support\Mode::selfHosted())
User-agent: *
Disallow: /
@else
User-agent: *
Disallow: /admin
Disallow: /account
Disallow: /api
Disallow: /login
Disallow: /register
Disallow: /claim

# Allow AI crawlers: citations in AI answers = free traffic
User-agent: GPTBot
Allow: /

User-agent: OAI-SearchBot
Allow: /

User-agent: ClaudeBot
Allow: /

User-agent: anthropic-ai
Allow: /

User-agent: PerplexityBot
Allow: /

User-agent: Google-Extended
Allow: /

Sitemap: {{ rtrim(config('app.url'), '/') }}/sitemap.xml
@endif
