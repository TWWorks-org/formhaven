<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Submit every URL in public/sitemap.xml to IndexNow (api.indexnow.org), which
 * fans out to Bing, Yandex, Naver, Seznam, Yep, and DuckDuckGo. Google ignores
 * IndexNow by design and discovers via the sitemap instead. The payoff is
 * faster Bing pickup, which feeds ChatGPT Search and Microsoft Copilot
 * citations (both read Bing's index). For a new domain this cuts Bing
 * discovery from days to minutes.
 *
 * The URL list is the sitemap itself: this reads the <loc> entries that
 * `sitemap:generate` already wrote, so the two can never drift out of sync.
 * The deploy runs `sitemap:generate` first, so the file is current.
 *
 * The key is a public domain-ownership token (NOT a secret) set as INDEXNOW_KEY
 * (config/services.php) and served at https://<your-domain>/<key>.txt by a route
 * in routes/web.php. On first run, if no key is set, one is generated and
 * printed: put it in the server's .env, deploy, then run this again to
 * submit. Each project gets its own key; never share one across domains.
 *
 * Run after a deploy that adds or materially changes pages:
 *   php artisan indexnow:submit
 *
 * Preview the URL list without submitting (writes nothing):
 *   php artisan indexnow:submit --dry-run
 */
class IndexNowSubmitCommand extends Command
{
    protected $signature = 'indexnow:submit {--dry-run : Print the URL list without submitting}';

    protected $description = 'Submit sitemap URLs to IndexNow (Bing/Yandex/Naver/etc.)';

    public function handle(): int
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (! $host || $host === 'localhost' || str_starts_with($host, '127.')) {
            $this->error("Refusing to submit for host '{$host}'. Set APP_URL to the live domain.");

            return self::FAILURE;
        }

        $urls = $this->sitemapUrls($host);
        if ($urls === []) {
            $this->error('No URLs found in public/sitemap.xml. Run `php artisan sitemap:generate` first.');

            return self::FAILURE;
        }

        $key = $this->findKey();

        if ($this->option('dry-run')) {
            $this->info('[dry-run] Would submit '.count($urls)." URLs to IndexNow for {$host}:");
            foreach ($urls as $url) {
                $this->line('  '.$url);
            }
            $this->line($key
                ? '[dry-run] keyLocation: '.url("/{$key}.txt")
                : '[dry-run] No INDEXNOW_KEY set yet. Run without --dry-run to generate one.');

            return self::SUCCESS;
        }

        if (! $key) {
            $key = bin2hex(random_bytes(16));
            $this->info("Generated a new IndexNow key: {$key}");
            $this->line("Next: set INDEXNOW_KEY={$key} in the server's .env and deploy, so it is live at");
            $this->line('  '.url("/{$key}.txt"));
            $this->line('then run `php artisan indexnow:submit` again to submit.');

            return self::SUCCESS;
        }

        $response = Http::asJson()->post('https://api.indexnow.org/indexnow', [
            'host' => $host,
            'key' => $key,
            'keyLocation' => url("/{$key}.txt"),
            'urlList' => $urls,
        ]);

        // 200 = processed, 202 = accepted (key validation pending). Both fine.
        // A brand-new key often returns 403 SiteVerificationNotCompleted until
        // the engines fetch the key file; wait a bit, then retry.
        if (! in_array($response->status(), [200, 202], true)) {
            $this->error("IndexNow returned HTTP {$response->status()}: {$response->body()}");

            return self::FAILURE;
        }

        $this->info('Submitted '.count($urls)." URLs to IndexNow (HTTP {$response->status()}).");

        return self::SUCCESS;
    }

    /**
     * Read <loc> entries from public/sitemap.xml, keeping only URLs on our host.
     * Namespace-agnostic, so it works with the standard sitemap namespace Spatie
     * emits.
     *
     * @return array<int, string>
     */
    private function sitemapUrls(string $host): array
    {
        $path = public_path('sitemap.xml');
        if (! is_file($path)) {
            return [];
        }

        $dom = new \DOMDocument();
        if (! @$dom->load($path)) {
            return [];
        }

        $urls = [];
        foreach ($dom->getElementsByTagNameNS('*', 'loc') as $loc) {
            $value = trim($loc->textContent);
            if ($value !== '' && parse_url($value, PHP_URL_HOST) === $host) {
                $urls[$value] = true; // key-by-value dedupe
            }
        }

        return array_keys($urls);
    }

    /** The configured IndexNow key, or null when none (or a malformed one) is set. */
    private function findKey(): ?string
    {
        $key = trim((string) config('services.indexnow.key'));

        return preg_match('/^[a-zA-Z0-9-]{8,128}$/', $key) ? $key : null;
    }
}
