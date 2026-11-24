<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Behind Cloudflare and Caddy the app must see the visitor's IP, never one the visitor wrote in a
 * header. What arrives at PHP: REMOTE_ADDR is the Cloudflare address Caddy got the request from,
 * and X-Forwarded-For is whatever the visitor sent, then the address Cloudflare saw, then that
 * Cloudflare address (Caddy appends it, and keeps the header only from Cloudflare's ranges).
 */
class ClientIpTest extends TestCase
{
    use RefreshDatabase;

    private const CLOUDFLARE = '162.158.1.1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.trusted_proxies' => '*']);

        Route::get('/_test/ip', fn (Request $request) => response($request->ip().'|'.$request->getHost()))->middleware('throttle:2,1');
    }

    /** A request as Caddy hands it to PHP: the visitor's own header first, then Cloudflare's entries. */
    private function viaCloudflare(string $visitor, ?string $forged = null, array $headers = [])
    {
        $chain = implode(', ', array_filter([$forged, $visitor, self::CLOUDFLARE]));

        return $this->withServerVariables(['REMOTE_ADDR' => self::CLOUDFLARE])
            ->withHeaders(['X-Forwarded-For' => $chain] + $headers)
            ->get('/_test/ip');
    }

    private function host(): string
    {
        return parse_url(config('app.url'), PHP_URL_HOST);
    }

    public function test_the_app_sees_the_address_cloudflare_saw(): void
    {
        $this->viaCloudflare('203.0.113.7')->assertOk()->assertSeeText('203.0.113.7|'.$this->host());
        $this->viaCloudflare('2001:db8::42')->assertOk()->assertSeeText('2001:db8::42|');
    }

    public function test_a_forged_forwarded_for_header_is_ignored(): void
    {
        $this->viaCloudflare('203.0.113.8', forged: '6.6.6.6')->assertSeeText('203.0.113.8|');
        // A made-up Cloudflare-looking address, or several, change nothing either.
        $this->viaCloudflare('203.0.113.9', forged: '162.158.9.9, 10.0.0.1')->assertSeeText('203.0.113.9|');
    }

    public function test_only_the_forwarded_for_header_is_trusted(): void
    {
        $this->viaCloudflare('203.0.113.10', headers: ['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'http'])
            ->assertSeeText('203.0.113.10|'.$this->host());
    }

    public function test_rate_limits_count_each_visitor_on_its_own(): void
    {
        $this->viaCloudflare('203.0.113.20')->assertOk();
        $this->viaCloudflare('203.0.113.20')->assertOk();
        $this->viaCloudflare('203.0.113.20')->assertStatus(429);
        // A forged header does not buy a fresh count.
        $this->viaCloudflare('203.0.113.20', forged: '6.6.6.'.random_int(1, 254))->assertStatus(429);

        // Other visitors behind the same Cloudflare address are not held by the first one.
        $this->viaCloudflare('203.0.113.21')->assertOk();
        $this->viaCloudflare('198.51.100.5')->assertOk();
    }

    public function test_without_trusted_proxies_the_header_is_ignored(): void
    {
        config(['app.trusted_proxies' => null]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
            ->withHeaders(['X-Forwarded-For' => '6.6.6.6'])
            ->get('/_test/ip')
            ->assertSeeText('198.51.100.77|');
    }

    public function test_session_rows_hold_no_ip_address(): void
    {
        config(['session.driver' => 'database']);

        $this->withServerVariables(['REMOTE_ADDR' => self::CLOUDFLARE])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.30, '.self::CLOUDFLARE, 'User-Agent' => 'TestBrowser/1.0'])
            ->get('/login')
            ->assertOk();

        $row = DB::table('sessions')->sole();
        $this->assertNull($row->ip_address);
        $this->assertSame('TestBrowser/1.0', $row->user_agent);
    }
}
