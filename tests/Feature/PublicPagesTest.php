<?php

namespace Tests\Feature;

use App\Http\Controllers\ContentController;
use App\Http\Controllers\LegalController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    /** Launch blockers: files linked from pages that do not exist yet. Remove each entry once it does. */
    private const ASSETS_NOT_MADE_YET = [];

    private function publicPaths(): array
    {
        return array_merge(
            ['/', '/pricing', '/templates', '/guides'],
            array_map(fn ($p) => "/legal/{$p}", array_keys(LegalController::PAGES)),
            array_map(fn ($s) => "/templates/{$s}", array_keys(ContentController::templates())),
            array_map(fn ($s) => "/alternatives/{$s}", array_keys(ContentController::alternatives())),
            array_map(fn ($s) => "/guides/{$s}", array_keys(ContentController::guides())),
        );
    }

    public function test_every_public_page_loads_with_its_own_title(): void
    {
        $titles = [];
        foreach ($this->publicPaths() as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            preg_match('#<title>(.*?)</title>#s', $html, $m);
            $titles[] = $m[1] ?? '';
            // A component tag Blade could not parse is printed as raw text and the browser hides it,
            // so a page can lose its whole hero and still answer 200 (the Astro guide, 2026-11-04).
            $this->assertStringNotContainsString('<x-', $html, "{$path} has an unrendered Blade component");
            $this->assertSame(1, substr_count($html, '<h1'), "{$path} should have exactly one h1");
        }

        $this->assertSame(count($titles), count(array_unique($titles)), 'Two public pages share a title');
    }

    public function test_every_internal_link_on_public_pages_works(): void
    {
        $links = [];
        foreach ($this->publicPaths() as $path) {
            preg_match_all('#href="([^"]+)"#', $this->get($path)->getContent(), $m);
            foreach ($m[1] as $href) {
                $url = parse_url(html_entity_decode($href));
                if (isset($url['scheme']) && ! in_array($url['scheme'], ['http', 'https'], true)) {
                    continue; // mailto: and similar
                }
                $host = $url['host'] ?? null;
                if ($host && $host !== parse_url(config('app.url'), PHP_URL_HOST)) {
                    continue; // external
                }
                if (isset($url['path']) && ! str_contains($url['path'], '/build/') && ! str_ends_with($url['path'], '.css')) {
                    $links[$url['path']] = $path;
                }
            }
        }

        $this->assertNotEmpty($links);
        foreach ($links as $link => $foundOn) {
            // Static files are served by Caddy, not Laravel: check they exist on disk.
            if (preg_match('#\.(ico|png|svg|txt|xml)$#', $link) && $this->get($link)->status() === 404) {
                if (in_array($link, self::ASSETS_NOT_MADE_YET, true)) {
                    continue;
                }
                $this->assertFileExists(public_path(ltrim($link, '/')), "{$link} (linked from {$foundOn}) is missing");

                continue;
            }
            $status = $this->get($link)->status();
            $this->assertContains($status, [200, 302], "{$link} (linked from {$foundOn}) answered {$status}");
        }
    }

    public function test_landing_and_pricing_quote_the_configured_prices(): void
    {
        $this->get('/')
            ->assertSeeText('€1 per form a month')
            ->assertSee('Then €1 per form a month, €0.50 from the 11th form, every feature included.')
            ->assertSee('Start your '.config('billing.trial_days').'-day trial');

        // The pages follow the config, never a typed number.
        config(['billing.per_form.monthly_eur' => 3, 'billing.per_form.yearly_eur' => 30, 'billing.per_form.min_forms' => 4, 'billing.per_form.lower_monthly_eur' => 1.5]);
        // 25 forms: 10 at €3 and 15 at €1.50.
        $this->get('/pricing')->assertSeeText('€3 per form a month')->assertSeeText('€30 per form a year')->assertSeeText('For at least 4 forms')->assertSeeText('€52.50 a month')->assertSeeText('From the 11th form, €1.50.');
        $this->get('/')->assertSeeText('€3 per form a month')->assertSee('Then €3 per form a month');
    }

    public function test_legal_pages_fill_in_who_we_are_and_render_tables(): void
    {
        config(['legal.entity' => 'Example Holding B.V.', 'legal.kvk' => '12345678']);

        $this->get('/legal/privacy')->assertSee('Example Holding B.V.')->assertSee('KvK 12345678')
            ->assertDontSee(':entity');
        $this->get('/legal/subprocessors')->assertSee('<table>', false)->assertSee('Hetzner Online GmbH');
        $this->get('/legal/terms')->assertSee(config('billing.trial_days').' days');
    }

    public function test_llms_txt_is_plain_text_and_states_the_configured_facts(): void
    {
        $response = $this->get('/llms.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $text = $response->getContent();
        $p = config('billing.per_form');

        $this->assertStringContainsString('€'.$p['monthly_eur'].' per form a month (€'.$p['yearly_eur'].' per form a year), for at least '.$p['min_forms'].' forms', $text);
        $this->assertStringContainsString('each form includes '.number_format($p['submissions']).' a month', $text);
        $this->assertStringContainsString('DELETE '.url('/api/v1/forms/{id}'), $text);
        $this->assertStringContainsString(config('billing.trial_days').' days', $text);
        $this->assertStringContainsString(url('/mcp'), $text);
        $this->assertStringContainsString('?spam=0&per_page=25', $text);
        $this->assertStringNotContainsString('&amp;', $text);
    }

    public function test_every_public_page_shows_who_runs_the_site_but_no_btw_number(): void
    {
        config(['legal.entity' => 'Example Trading', 'legal.kvk' => '87654321', 'legal.address' => 'Postbus 1, 1000 AA Amsterdam']);

        foreach ($this->publicPaths() as $path) {
            $this->get($path)
                ->assertSee('Example Trading')
                ->assertSee('KvK 87654321')
                ->assertSee('Postbus 1, 1000 AA Amsterdam')
                ->assertSee(config('legal.email'))
                ->assertDontSee('BTW')
                ->assertDontSee('VAT number');
        }
    }

    public function test_site_noindex_hides_a_production_site_from_search(): void
    {
        $this->app['env'] = 'production';

        $this->get('/')->assertHeaderMissing('X-Robots-Tag');

        config(['app.noindex' => true]);
        $this->get('/')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_without_an_address_no_empty_gap_or_placeholder_is_shown(): void
    {
        config(['legal.entity' => 'Example Forms B.V.', 'legal.kvk' => '12345678', 'legal.address' => null]);

        $this->get('/')->assertSee('Example Forms B.V. · KvK 12345678 · ', false)->assertDontSee('·  ·', false);
        $this->get('/legal/privacy')->assertSee('Example Forms B.V., KvK 12345678.')->assertDontSee(':address');
        $this->get('/legal/terms')->assertSee('between you and Example Forms B.V. (KvK 12345678)');
    }

    public function test_an_unknown_legal_page_is_a_404(): void
    {
        $this->get('/legal/cookies')->assertNotFound()
            ->assertSee('This page does not exist')
            ->assertSee(route('pricing'));
        $this->get('/legal/..%2F.env')->assertNotFound();
    }
}
