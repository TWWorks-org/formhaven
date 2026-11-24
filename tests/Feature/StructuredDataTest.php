<?php

namespace Tests\Feature;

use App\Http\Controllers\ContentController;
use App\Http\Controllers\LegalController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every JSON-LD block on a public page must be valid JSON a search engine can read.
 * Blade compiles a token like '@context' as its own @context directive even inside {!! !!},
 * which once leaked "<?php $__contextArgs" into every page's schema (2026-11-12 to 2026-11-21).
 *
 * The schema must also say what the page visibly says: a FAQPage carries exactly the questions
 * and answers a visitor reads, a BreadcrumbList links only pages that answer 200, and nothing
 * claims a date, a rating or a person the content does not have.
 */
class StructuredDataTest extends TestCase
{
    use RefreshDatabase;

    /** Path => the schema types the page must carry (the site layout adds Organization to every page). */
    private function pages(): array
    {
        $pages = [
            '/' => ['Organization', 'SoftwareApplication', 'FAQPage'],
            '/pricing' => ['Organization', 'FAQPage'],
        ];

        $paths = array_merge(
            ['/templates', '/guides', '/report'],
            array_map(fn ($p) => "/legal/{$p}", array_keys(LegalController::PAGES)),
            array_map(fn ($s) => "/alternatives/{$s}", array_keys(ContentController::alternatives())),
        );

        foreach ($paths as $path) {
            $pages[$path] = ['Organization'];
        }
        foreach (array_keys(ContentController::templates()) as $slug) {
            $pages["/templates/{$slug}"] = ['Organization', 'BreadcrumbList', 'FAQPage'];
        }
        foreach (array_keys(ContentController::guides()) as $slug) {
            $pages["/guides/{$slug}"] = ['Organization', 'Article', 'BreadcrumbList', 'FAQPage'];
        }

        return $pages;
    }

    /** @return list<array<mixed>> */
    private function blocks(string $path, string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        return array_map(function (string $raw) use ($path) {
            $data = json_decode($raw, true);
            $this->assertIsArray($data, "{$path}: a JSON-LD block is not valid JSON (".json_last_error_msg()."):\n".substr($raw, 0, 300));

            return $data;
        }, $m[1]);
    }

    /** @return list<array<mixed>> every schema item on the page of one @type */
    private function itemsOfType(array $blocks, string $type): array
    {
        $items = [];
        foreach ($blocks as $block) {
            foreach ($block['@graph'] ?? [$block] as $item) {
                if (($item['@type'] ?? null) === $type) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    private function text(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
    }

    /** @return list<array{0: string, 1: string}> the question and answer pairs a visitor reads on the page */
    private function visibleFaq(string $html): array
    {
        preg_match_all('#<dt\b[^>]*>(.*?)</dt>\s*<dd\b[^>]*>(.*?)</dd>#s', $html, $m, PREG_SET_ORDER);

        return array_map(fn (array $pair) => [$this->text($pair[1]), $this->text($pair[2])], $m);
    }

    private function assertNoLeakedBlade(string $path, string $html): void
    {
        // Only strings compiled Blade leaves behind: guides show Hugo, Jekyll and JSX samples with {{ }} on purpose.
        foreach (['<?php', '$__', '__contextArgs', '@@context', '{!!', '!!}', '@endphp'] as $leak) {
            $this->assertStringNotContainsString($leak, $html, "{$path}: leaked template code '{$leak}' in the HTML");
        }
    }

    private function assertValidBlocks(string $path, array $blocks, array $expectedTypes): void
    {
        $this->assertNotEmpty($blocks, "{$path}: no JSON-LD block");
        $types = [];

        foreach ($blocks as $block) {
            $this->assertSame('https://schema.org', $block['@context'] ?? null, "{$path}: @context is not https://schema.org");
            $items = $block['@graph'] ?? [$block];
            foreach ($items as $item) {
                $this->assertNotEmpty($item['@type'] ?? null, "{$path}: a schema item has no @type");
                $types[] = $item['@type'];
            }
        }

        foreach ($expectedTypes as $type) {
            $this->assertContains($type, $types, "{$path}: no {$type} schema");
        }
    }

    /** Strings must reach the JSON as plain text: an entity inside a JSON string is shown to the reader as typed. */
    private function assertPlainStrings(string $path, array $data): void
    {
        array_walk_recursive($data, function ($value, $key) use ($path) {
            $this->assertNotContains($key, ['aggregateRating', 'review', 'reviewRating'], "{$path}: a rating or review in the schema, and FormHaven has none");
            if (is_string($value)) {
                $this->assertDoesNotMatchRegularExpression('/&(amp|lt|gt|quot|#0?39|#\d+|euro|nbsp);/', $value, "{$path}: an HTML entity inside a JSON-LD string: {$value}");
            }
        });
    }

    public function test_every_public_page_has_valid_json_ld_and_no_leaked_template_code(): void
    {
        foreach ($this->pages() as $path => $expectedTypes) {
            $html = $this->get($path)->assertOk()->getContent();

            $this->assertNoLeakedBlade($path, $html);
            $blocks = $this->blocks($path, $html);
            $this->assertValidBlocks($path, $blocks, $expectedTypes);
            $this->assertPlainStrings($path, $blocks);
            // SoftwareApplication on the home page is the one product block; a second would compete with it.
            $this->assertSame([], $this->itemsOfType($blocks, 'Product'), "{$path}: a Product block next to SoftwareApplication");
        }
    }

    public function test_a_faq_schema_carries_exactly_the_faq_a_visitor_reads_on_every_page(): void
    {
        $withFaq = 0;

        foreach (array_keys($this->pages()) as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $faqs = $this->itemsOfType($this->blocks($path, $html), 'FAQPage');
            $visible = $this->visibleFaq($html);

            if ($visible === []) {
                $this->assertSame([], $faqs, "{$path}: FAQPage schema on a page with no visible FAQ");

                continue;
            }

            $withFaq++;
            $this->assertCount(1, $faqs, "{$path}: shows a FAQ, so it needs one FAQPage block");
            $schema = array_map(fn (array $q) => [
                $q['name'],
                trim(preg_replace('/\s+/u', ' ', $q['acceptedAnswer']['text'])),
            ], $faqs[0]['mainEntity']);

            $this->assertSame($visible, $schema, "{$path}: the FAQPage questions or answers differ from the visible FAQ");
            foreach ($faqs[0]['mainEntity'] as $q) {
                $this->assertSame('Question', $q['@type']);
                $this->assertSame('Answer', $q['acceptedAnswer']['@type']);
                $this->assertStringNotContainsString('<', $q['acceptedAnswer']['text'], "{$path}: HTML left in a FAQ answer");
            }
        }

        // Home, pricing, every guide and every template.
        $this->assertSame(2 + count(ContentController::guides()) + count(ContentController::templates()), $withFaq);
    }

    public function test_home_and_pricing_faq_answers_are_the_visible_text(): void
    {
        $home = $this->itemsOfType($this->blocks('/', $this->get('/')->getContent()), 'FAQPage')[0]['mainEntity'] ?? [];
        $byQuestion = array_combine(array_column($home, 'name'), array_column(array_column($home, 'acceptedAnswer'), 'text'));

        // The llms.txt answer carries a link on the page: the schema keeps its words, not its tag.
        $this->assertStringEndsWith('The details are in llms.txt.', $byQuestion['Can an AI agent set it up for me?'] ?? '');
        $this->assertStringContainsString(\App\Support\Bill::format(config('billing.per_form.monthly_eur')).' per form a month', $byQuestion['Is there a free plan?'] ?? '');

        $pricing = $this->itemsOfType($this->blocks('/pricing', $this->get('/pricing')->getContent()), 'FAQPage')[0]['mainEntity'] ?? [];
        $byQuestion = array_combine(array_column($pricing, 'name'), array_column(array_column($pricing, 'acceptedAnswer'), 'text'));
        $this->assertSame('Cancel any time from the billing page. Your forms keep their addresses.', $byQuestion['Can I cancel?'] ?? null);
        $this->assertStringContainsString(config('billing.trial_days').' days instead', $byQuestion['Why is there no free plan?'] ?? '');
    }

    public function test_every_guide_is_an_article_by_the_organisation_with_no_invented_date(): void
    {
        foreach (ContentController::guides() as $slug => $guide) {
            $path = "/guides/{$slug}";
            $articles = $this->itemsOfType($this->blocks($path, $this->get($path)->getContent()), 'Article');

            $this->assertCount(1, $articles, "{$path}: one Article block");
            $article = $articles[0];
            $this->assertSame($guide['h1'], $article['headline'], "{$path}: headline is not the page's H1");
            $this->assertSame($guide['description'], $article['description'], "{$path}: description is not the page's meta description");
            $this->assertSame(route('guides.show', $slug), $article['url']);
            $this->assertSame(route('guides.show', $slug), $article['mainEntityOfPage']['@id']);

            foreach (['author', 'publisher'] as $role) {
                $this->assertSame('Organization', $article[$role]['@type'], "{$path}: {$role} must be the organisation, never a person");
                $this->assertSame(config('app.name'), $article[$role]['name']);
            }

            // The guide content holds no dates, so the schema claims none (today's date would be a lie).
            $this->assertArrayNotHasKey('datePublished', $article, "{$path}: a date the content does not have");
            $this->assertArrayNotHasKey('dateModified', $article, "{$path}: a date the content does not have");
        }
    }

    public function test_breadcrumbs_on_guides_and_templates_follow_the_visible_trail_and_link_real_pages(): void
    {
        $expected = [];
        foreach (ContentController::guides() as $slug => $guide) {
            $expected["/guides/{$slug}"] = [['Home', route('home')], ['Guides', route('guides.index')], [$guide['name'], route('guides.show', $slug)]];
        }
        foreach (ContentController::templates() as $slug => $template) {
            $expected["/templates/{$slug}"] = [['Home', route('home')], ['Form templates', route('templates.index')], [$template['name'], route('templates.show', $slug)]];
        }

        foreach ($expected as $path => $trail) {
            $crumbs = $this->itemsOfType($this->blocks($path, $this->get($path)->getContent()), 'BreadcrumbList');
            $this->assertCount(1, $crumbs, "{$path}: one BreadcrumbList");

            $items = $crumbs[0]['itemListElement'];
            $this->assertSame(range(1, count($trail)), array_column($items, 'position'), "{$path}: positions run 1, 2, 3");
            $this->assertSame($trail, array_map(fn ($i) => [$i['name'], $i['item']], $items), "{$path}: breadcrumb trail");

            foreach ($items as $item) {
                $this->get(parse_url($item['item'], PHP_URL_PATH) ?: '/')->assertOk();
            }
        }
    }

    public function test_the_404_page_has_valid_json_ld_too(): void
    {
        $html = $this->get('/this-page-does-not-exist')->assertNotFound()->getContent();

        $this->assertNoLeakedBlade('404', $html);
        $this->assertValidBlocks('404', $this->blocks('404', $html), ['Organization']);
    }
}
