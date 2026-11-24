<?php

namespace Tests\Feature;

use App\Mcp\Servers\FormHavenServer;
use App\Support\ApiIndex;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * What the pages and llms.txt tell agents must match what the code does: every tool and every
 * route documented, the storage place read from config, and no template placeholder in the
 * structured data. A new tool or route that nobody documents fails here.
 */
class AgentCopyTest extends TestCase
{
    private function mainOf(string $html): string
    {
        preg_match('#<main\b[^>]*>(.*)</main>#s', $html, $m);

        return html_entity_decode(strip_tags($m[1] ?? ''));
    }

    public function test_the_api_index_lists_exactly_the_routes_the_router_has(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'))
            ->flatMap(fn ($route) => collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->map(fn ($m) => $m.' /'.$route->uri()))
            ->sort()->values()->all();

        $this->assertSame($routes, collect(array_keys(ApiIndex::ENDPOINTS))->sort()->values()->all());
        $this->getJson('/api')->assertJsonPath('endpoints', array_keys(ApiIndex::ENDPOINTS));
    }

    public function test_llms_txt_documents_every_tool_and_every_route(): void
    {
        $llms = $this->get('/llms.txt')->assertOk()->getContent();

        foreach (FormHavenServer::toolNames() as $tool) {
            $this->assertStringContainsString($tool, $llms, "llms.txt does not name the {$tool} tool");
        }
        foreach (array_keys(ApiIndex::ENDPOINTS) as $endpoint) {
            [, $path] = explode(' ', $endpoint);
            $this->assertStringContainsString(url($path), $llms, "llms.txt does not document {$endpoint}");
        }
        $this->assertStringContainsString('## Start without an account', $llms);
        $this->assertStringContainsString('FORM_NOT_ACTIVE', $llms);
    }

    public function test_llms_txt_names_every_field_patch_accepts(): void
    {
        $llms = $this->get('/llms.txt')->assertOk()->getContent();

        foreach (\App\Http\Controllers\Api\FormController::patchable() as $field) {
            $this->assertStringContainsString('"'.$field.'"', $llms, "llms.txt does not document the PATCH field {$field}");
        }
    }

    public function test_every_mcp_tool_has_a_readable_title(): void
    {
        $tools = (new \ReflectionClass(FormHavenServer::class))->getDefaultProperties()['tools'];

        foreach ($tools as $tool) {
            $title = (new \ReflectionClass($tool))->getAttributes(\Laravel\Mcp\Server\Attributes\Title::class);
            $this->assertNotEmpty($title, "{$tool} has no #[Title]: Claude would show its class name");
            $this->assertStringNotContainsString('Tool', $title[0]->getArguments()[0]);

            // Claude's directory requires every tool to declare readOnlyHint or destructiveHint.
            $class = new \ReflectionClass($tool);
            $this->assertTrue(
                $class->getAttributes(\Laravel\Mcp\Server\Tools\Annotations\IsReadOnly::class) !== []
                || $class->getAttributes(\Laravel\Mcp\Server\Tools\Annotations\IsDestructive::class) !== [],
                "{$tool} declares neither IsReadOnly nor IsDestructive"
            );
        }
    }

    public function test_the_home_page_lists_the_tools_the_server_has(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        preg_match('#<ul data-tools [^>]*>(.*?)</ul>#s', $html, $list);
        preg_match_all('#<li [^>]*>(.*?)</li>#s', $list[1] ?? '', $items);

        $this->assertSame(FormHavenServer::toolNames()->all(), array_map('trim', $items[1]));
    }

    public function test_the_reference_guide_lists_every_tool_and_route(): void
    {
        $main = $this->mainOf($this->get('/guides/form-backend-for-ai-agents')->assertOk()->getContent());

        foreach (FormHavenServer::tools() as $tool => $description) {
            $this->assertStringContainsString($tool, $main);
            $this->assertStringContainsString(explode("\n", wordwrap($description, 64))[0], $main);
        }
        foreach (array_keys(ApiIndex::ENDPOINTS) as $endpoint) {
            $this->assertStringContainsString($endpoint, $main);
        }
    }

    public function test_the_storage_place_comes_from_config_everywhere_it_is_stated(): void
    {
        $country = trim(explode(',', config('legal.hosting.place.en'))[1]);

        $this->assertStringContainsString($country, $this->mainOf($this->get('/')->getContent()));
        $this->assertStringContainsString(config('legal.hosting.place.en'), $this->get('/llms.txt')->getContent());
        $this->assertStringContainsString(config('legal.hosting.place.en'), $this->mainOf($this->get('/guides/contact-form-privacy-policy')->getContent()));
    }

    /**
     * Who sends the emails is stated in the legal pages and in the privacy text a form generates,
     * not in the selling copy (owner, 2026-11-21). The home page still shows that generated text
     * as an example, and names a rival's database; both are taken out before the check.
     */
    public function test_the_selling_pages_leave_the_email_sender_to_the_legal_pages(): void
    {
        $mailer = explode(',', config('legal.mailer'))[0];
        // The <head> and every JSON-LD block. The footer is left out: it links the Cloudflare Pages guide.
        $meta = function (string $html): string {
            preg_match('#<head\b.*?</head>#s', $html, $head);
            preg_match_all('#<script type="application/ld\+json">.*?</script>#s', $html, $ld);
            $this->assertNotEmpty($head);

            return $head[0].implode('', $ld[0]);
        };

        $home = $this->get('/')->assertOk()->getContent();
        $main = $this->mainOf($home);
        foreach (\App\Support\PrivacyText::LANGUAGES as $lang) {
            $example = \App\Support\PrivacyText::for(new \App\Models\Form(['retention_days' => \App\Models\Form::DEFAULT_RETENTION_DAYS]), $lang)['paragraph'];
            $this->assertStringContainsString($example, $main, "The home page no longer shows the {$lang} privacy text example");
            $main = str_replace($example, '', $main);
        }
        $main = str_replace('Cloudflare D1', '', $main);
        $this->assertStringNotContainsStringIgnoringCase($mailer, $main, 'The home page names who sends the emails outside the privacy text example');
        $this->assertStringNotContainsStringIgnoringCase($mailer, $meta($home), 'The home page title, description or JSON-LD names who sends the emails');

        $pricing = $this->get('/pricing')->assertOk()->getContent();
        $this->assertStringNotContainsStringIgnoringCase($mailer, $this->mainOf($pricing));
        $this->assertStringNotContainsStringIgnoringCase($mailer, $meta($pricing));
        // Each guide links the other guides, the one for sites hosted on Cloudflare Pages among them.
        $guide = str_replace('Cloudflare Pages', '', $this->mainOf($this->get('/guides/gdpr-compliant-contact-form')->assertOk()->getContent()));
        $this->assertStringNotContainsStringIgnoringCase($mailer, $guide, 'The GDPR guide names who sends the emails');
        // The German page shows the generated German privacy paragraph, wrapped at 72 columns as on the page.
        $german = $this->mainOf($this->get('/guides/kontaktformular-dsgvo')->assertOk()->getContent());
        $example = \App\Support\PrivacyText::for(new \App\Models\Form(['retention_days' => \App\Models\Form::DEFAULT_RETENTION_DAYS]), 'de')['paragraph'];
        $this->assertStringContainsString(wordwrap($example, 72), $german, 'The German page no longer shows the privacy text example');
        $german = str_replace([wordwrap($example, 72), 'Cloudflare Pages'], '', $german);
        $this->assertStringNotContainsStringIgnoringCase($mailer, $german, 'The German page names who sends the emails outside the privacy text example');

        foreach (['privacy', 'terms', 'dpa', 'subprocessors'] as $page) {
            $this->assertStringContainsString($mailer, $this->get("/legal/{$page}")->assertOk()->getContent(), "/legal/{$page} must keep naming who sends the emails");
        }
    }

    public function test_the_home_title_names_the_category_and_the_agent_promise(): void
    {
        $html = $this->get('/')->getContent();
        preg_match('#<title>(.*?)</title>#', $html, $m);

        $this->assertStringContainsStringIgnoringCase('form backend', $m[1]);
        $this->assertStringContainsStringIgnoringCase('agent', $m[1]);
        $this->assertStringContainsString(trim(explode(',', config('legal.hosting.place.en'))[1]), $m[1]);
    }

    public function test_no_page_carries_template_placeholders_in_its_structured_data(): void
    {
        foreach (['/', '/pricing', '/guides/form-backend-for-ai-agents', '/legal/privacy'] as $path) {
            preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $this->get($path)->getContent(), $m);
            $this->assertNotEmpty($m[1], $path);
            foreach ($m[1] as $json) {
                $this->assertIsArray(json_decode($json, true), "{$path} has JSON-LD that does not parse");
                foreach (['One sentence about', 'Your Name', 'yourhandle', 'yourorg', 'What your software does'] as $placeholder) {
                    $this->assertStringNotContainsString($placeholder, $json, "{$path} still has \"{$placeholder}\" in its JSON-LD");
                }
            }
        }
    }

    public function test_the_home_page_names_the_send_again_button_the_dashboard_really_has(): void
    {
        $main = $this->mainOf($this->get('/')->getContent());
        $dashboard = file_get_contents(resource_path('views/livewire/forms/show.blade.php'));

        $this->assertStringContainsString('you hear it when an inbox refuses one.', $main);
        $this->assertStringContainsString('press Send again', $main);
        // We detect a refused send, never mail sitting unread or in a spam folder, so
        // the home page must not promise to notice every message that "does not arrive".
        $this->assertStringNotContainsString('does not arrive', $main, 'Promise only what CloudflareTransport detects: a bounce');
        $this->assertStringContainsString('wire:click="retryDeliveries">Send again</flux:button>', $dashboard, 'The home page promises a "Send again" button: keep the label in step');
    }

    public function test_the_home_schema_quotes_prices_from_config(): void
    {
        $html = $this->get('/')->getContent();
        $p = config('billing.per_form');

        // Full checks of both intervals, on a changed config too: StructuredDataOffersTest.
        $this->assertStringContainsString('"price": "'.number_format($p['monthly_eur'], 2, '.', '').'"', $html);
        $this->assertStringContainsString('"price": "'.number_format($p['yearly_eur'], 2, '.', '').'"', $html);
        $this->assertStringContainsString('at least '.$p['min_forms'].' forms', $html);
    }
}
