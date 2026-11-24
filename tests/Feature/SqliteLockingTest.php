<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Production logged "database is locked" on `update "cache"` (the rate limiter's counter,
 * 2026-11-16 to 11-20) while busy_timeout was already 60 s. A transaction that reads and
 * then writes fails at once, skipping the busy timeout, when another connection started
 * writing in between. Starting it with BEGIN IMMEDIATE takes the write lock first. Runs on
 * a real file with the app's own sqlite connection settings, since :memory: has no locks.
 */
class SqliteLockingTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'locktest');
        config(['database.connections.locktest' => array_merge(config('database.connections.sqlite'), ['database' => $this->file])]);
        DB::connection('locktest')->statement('create table counters (key text primary key, value integer)');
        DB::connection('locktest')->table('counters')->insert(['key' => 'submit', 'value' => 1]);
    }

    protected function tearDown(): void
    {
        DB::purge('locktest');
        @unlink($this->file);

        parent::tearDown();
    }

    public function test_a_read_then_write_transaction_is_not_broken_by_a_second_writer(): void
    {
        $other = new PDO('sqlite:'.$this->file);
        $other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $other->setAttribute(PDO::ATTR_TIMEOUT, 0);

        DB::connection('locktest')->transaction(function ($db) use ($other) {
            $value = $db->table('counters')->where('key', 'submit')->value('value');

            // Another request tries to write between our read and our write. With BEGIN
            // IMMEDIATE it has to wait for us; with a deferred begin it gets in first.
            try {
                $other->exec('BEGIN IMMEDIATE');
                $other->exec("update counters set value = 100 where key = 'other'");
            } catch (PDOException) {
                // Locked out, as it should be: it would retry under its own busy timeout.
            }

            $db->table('counters')->where('key', 'submit')->update(['value' => $value + 1]);
        });

        $this->assertSame(2, (int) DB::connection('locktest')->table('counters')->where('key', 'submit')->value('value'));
    }

    public function test_the_busy_timeout_is_short_enough_not_to_hang_a_request(): void
    {
        $timeout = (int) DB::connection('locktest')->selectOne('pragma busy_timeout')->timeout;

        $this->assertGreaterThan(0, $timeout);
        $this->assertLessThanOrEqual(10000, $timeout, 'A stuck lock would hold a visitor\'s request this long');
    }
}
