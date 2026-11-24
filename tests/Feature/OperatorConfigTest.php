<?php

namespace Tests\Feature;

use App\Models\DeliveryFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The operator's domain, inboxes and IndexNow key come from config, never from code
 * (config/legal.php, config/services.php). Every test sets invented values, and the
 * "empty" tests check that an install without them shows its own sending address
 * instead of someone else's or a broken link.
 */
class OperatorConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://forms.example.test',
            'legal.support_email' => 'support@forms.example.test',
            'legal.email' => 'privacy@forms.example.test',
        ]);
    }

    public function test_robots_txt_names_this_sites_sitemap(): void
    {
        $response = $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8');

        $this->assertStringStartsWith("User-agent: *\nDisallow: /admin\n", $response->getContent());
        $this->assertStringEndsWith("\n\nSitemap: https://forms.example.test/sitemap.xml\n", $response->getContent());
    }

    public function test_security_txt_names_the_support_inbox_and_this_site(): void
    {
        $body = $this->get('/.well-known/security.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8')->getContent();

        $this->assertStringContainsString("\nContact: mailto:support@forms.example.test\n", $body);
        $this->assertStringEndsWith("\nCanonical: https://forms.example.test/.well-known/security.txt\n", $body);
    }

    public function test_the_indexnow_key_file_answers_only_for_the_configured_key(): void
    {
        $key = 'abcdef0123456789abcdef0123456789';

        config(['services.indexnow.key' => null]);
        $this->get("/{$key}.txt")->assertNotFound();

        config(['services.indexnow.key' => $key]);
        $response = $this->get("/{$key}.txt")->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->assertSame($key, $response->getContent(), 'The body is the key and nothing else');
        $this->get('/0123456789abcdef0123456789abcdef.txt')->assertNotFound();
    }

    public function test_public_pages_and_advice_name_the_configured_support_inbox(): void
    {
        $home = $this->get('/')->assertOk();
        $home->assertSee('href="mailto:support@forms.example.test"', false);
        $home->assertSee('"email": "support@forms.example.test"', false);

        $failure = new DeliveryFailure(['kind' => 'bounced']);
        $this->assertStringContainsString('write to support@forms.example.test', $failure->fix());
    }

    public function test_an_install_without_the_inbox_settings_uses_its_own_sending_address(): void
    {
        // What config/legal.php does when SUPPORT_EMAIL and LEGAL_EMAIL are empty.
        $set = ['SUPPORT_EMAIL' => '', 'LEGAL_EMAIL' => '', 'MAIL_FROM_ADDRESS' => 'hello@stranger.example'];
        $saved = [];
        foreach ($set as $name => $value) {
            $saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv("{$name}={$value}");
        }
        try {
            $legal = require config_path('legal.php');
        } finally {
            foreach ($saved as $name => [$server, $env, $put]) {
                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }
                putenv($put === false ? $name : "{$name}={$put}");
            }
        }

        $this->assertSame('hello@stranger.example', $legal['support_email']);
        $this->assertSame('hello@stranger.example', $legal['email']);
    }
}
