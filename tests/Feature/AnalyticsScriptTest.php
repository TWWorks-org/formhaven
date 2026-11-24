<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The self-hosted Umami (resources/views/partials/analytics.blade.php), set up
 * from config here with invented values, so no test pins the live install.
 * It must load on public and sign-in pages, and never where a URL carries a
 * one-time value or someone is signed in: the privacy policy promises both.
 * Each "no tracker" check also asserts the page rendered, because an absence
 * check alone passes on an error page.
 */
class AnalyticsScriptTest extends TestCase
{
    use RefreshDatabase;

    private const SCRIPT = 'https://analytics.example.test/script.js';

    private const WEBSITE_ID = '00000000-0000-4000-8000-000000000001';

    private const DOMAIN = 'forms.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://'.self::DOMAIN,
            'services.umami.website_id' => self::WEBSITE_ID,
            'services.umami.script_url' => self::SCRIPT,
        ]);
    }

    public function test_public_and_sign_in_pages_load_the_tracker_for_this_site(): void
    {
        foreach (['/', '/login'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee(self::SCRIPT, false)
                ->assertSee('data-website-id="'.self::WEBSITE_ID.'"', false)
                ->assertSee('data-domains="'.self::DOMAIN.'"', false)
                ->assertSee('data-do-not-track="true"', false)
                ->assertSee('data-performance="true"', false)
                ->assertSee('data-errors="true"', false);
        }
    }

    public function test_an_install_with_no_website_id_renders_no_analytics_at_all(): void
    {
        config(['services.umami.website_id' => null]);

        foreach (['/', '/login'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('</head>', false)
                ->assertDontSee(self::SCRIPT, false)
                ->assertDontSee('data-website-id', false)
                ->assertDontSee('umamiBeforeSend', false);
        }
    }

    public function test_an_install_with_an_id_but_no_script_url_renders_no_analytics(): void
    {
        config(['services.umami.script_url' => '']);

        $this->get('/')
            ->assertOk()
            ->assertSee('</head>', false)
            ->assertDontSee('data-website-id', false)
            ->assertDontSee('umamiBeforeSend', false);
    }

    public function test_a_password_reset_link_loads_no_tracker(): void
    {
        $this->get('/reset-password/one-time-token-123?email=someone%40example.com')
            ->assertOk()
            ->assertSee('</head>', false)
            ->assertDontSee(self::SCRIPT, false)
            ->assertDontSee('umamiBeforeSend', false);
    }

    public function test_a_signed_in_visitor_loads_no_tracker(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('</head>', false)
            ->assertDontSee(self::SCRIPT, false);
    }

    public function test_the_query_string_is_cut_to_campaign_tags_before_sending(): void
    {
        $this->get('/')
            ->assertSee('data-before-send="umamiBeforeSend"', false)
            ->assertSee("k.indexOf('utm_') === 0", false)
            // error reports keep the tracker's own path-only URL
            ->assertSee("type !== 'error'", false);
    }

    public function test_no_signed_in_app_layout_includes_the_tracker(): void
    {
        $views = resource_path('views');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($views));
        $including = [];
        foreach ($files as $file) {
            if ($file->isFile() && str_contains(file_get_contents($file->getPathname()), "partials.analytics")) {
                $including[] = str_replace('\\', '/', substr($file->getPathname(), strlen($views) + 1));
            }
        }

        $this->assertNotEmpty($including);
        foreach ($including as $view) {
            $this->assertStringNotContainsString('app/', $view, "$view is a signed-in app layout");
        }
    }
}
