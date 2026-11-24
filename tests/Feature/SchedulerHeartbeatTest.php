<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * GET /up/scheduler is how a dead cron becomes visible from outside
 * (an external uptime monitor treats any 5xx as down).
 */
class SchedulerHeartbeatTest extends TestCase
{
    private string $tick;

    private ?int $kept = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tick = storage_path('framework/scheduler-tick');
        $this->kept = is_file($this->tick) ? filemtime($this->tick) : null;
        @unlink($this->tick);
    }

    protected function tearDown(): void
    {
        @unlink($this->tick);
        if ($this->kept !== null) {
            touch($this->tick, $this->kept);
        }
        parent::tearDown();
    }

    public function test_it_answers_503_when_the_scheduler_has_never_ticked(): void
    {
        $this->get('/up/scheduler')->assertStatus(503)->assertJson(['ok' => false, 'last_tick_seconds_ago' => null]);
    }

    public function test_it_answers_200_right_after_a_tick(): void
    {
        touch($this->tick);

        $this->get('/up/scheduler')->assertOk()->assertJson(['ok' => true]);
    }

    public function test_it_answers_503_once_the_last_tick_is_over_ten_minutes_old(): void
    {
        touch($this->tick, time() - 601);

        $this->get('/up/scheduler')->assertStatus(503)->assertJson(['ok' => false]);
    }

    public function test_it_still_answers_200_at_nine_minutes(): void
    {
        touch($this->tick, time() - 540);

        $this->get('/up/scheduler')->assertOk();
    }

    public function test_the_tick_is_scheduled_every_minute_and_running_it_makes_the_endpoint_healthy(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'scheduler-tick');

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);

        $event->run(app());

        $this->get('/up/scheduler')->assertOk();
    }
}
