<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The control for SelfHostedModeTest: the same list, the hosted way. phpunit.xml already sets
 * FORMHAVEN_MODE=hosted; it is set in $_SERVER here as well so the test says what it tests.
 */
class HostedModeControlTest extends TestCase
{
    use RefreshDatabase;

    private ?array $saved = null;

    protected function setUp(): void
    {
        $this->saved = array_key_exists('FORMHAVEN_MODE', $_SERVER) ? [$_SERVER['FORMHAVEN_MODE']] : null;
        $_SERVER['FORMHAVEN_MODE'] = 'hosted';

        parent::setUp();

        Mail::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->saved === null) {
            unset($_SERVER['FORMHAVEN_MODE']);
        } else {
            $_SERVER['FORMHAVEN_MODE'] = $this->saved[0];
        }
    }

    public function test_every_hosted_only_route_is_registered_and_answers(): void
    {
        foreach (SelfHostedModeTest::hostedOnlyRouteNames() as $name) {
            $this->assertTrue(Route::has($name), "route {$name} is missing");
        }

        foreach (SelfHostedModeTest::hostedOnlyGetUrls() as $url) {
            $this->get($url)->assertOk();
        }

        $this->get('/')->assertOk()->assertSee('Pricing');
        $this->actingAs(User::factory()->create())->get('/billing')->assertOk();
        $this->assertNotSame(404, $this->postJson('/stripe/webhook', [])->getStatusCode());
    }

    public function test_the_billing_command_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('billing:charge-extra')->assertExitCode(0);
    }

    public function test_registration_and_the_start_flow_stay_open_with_accounts_present(): void
    {
        User::factory()->create();

        $this->get('/register')->assertOk()->assertSee('terms of service');
        $this->get('/login')->assertSee('Sign up');
        $this->postJson('/api/v1/start', ['name' => 'Agent form'])->assertCreated();
    }

    public function test_a_new_account_gets_a_trial_and_a_form_pauses_once_it_ends(): void
    {
        $user = User::factory()->create();
        $this->assertNotNull($user->fresh()->trial_ends_at);
        $form = Form::factory()->for($user)->create();

        $this->travel(15)->days();

        $this->postJson("/f/{$form->public_id}", ['message' => 'Hello'])
            ->assertStatus(402)->assertJsonPath('errors.0.code', 'FORM_PAUSED');
    }

    public function test_robots_txt_and_the_operator_line_are_the_hosted_ones(): void
    {
        $this->assertStringStartsWith("User-agent: *\nDisallow: /admin\n", $this->get('/robots.txt')->getContent());
        $this->get('/')->assertSee('Example Forms B.V.')->assertSee('KvK 12345678')->assertSee('More from the same maker');
        $this->assertStringContainsString('submissions are stored in Germany', \App\Mcp\Servers\FormHavenServer::instructions());
    }
}
