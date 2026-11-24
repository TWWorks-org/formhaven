<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('submissions:prune')->daily();

// Bills last month's submissions past each paying account's pool; daily so a failed 1st retries (see the command).
// Hosted only: a self-hosted install bills nobody.
if (\App\Support\Mode::hosted()) {
    Schedule::command('billing:charge-extra')->dailyAt('06:00')->timezone('Europe/Amsterdam');
}
// The Monday summary to account owners (app/Console/Commands/SendWeeklyDigest.php).
Schedule::command('digest:send')->weeklyOn(1, '07:00')->timezone('Europe/Amsterdam');
// The weekly count to each form's recipients, after it. Daily, so whoever the cap left over on
// Monday gets theirs the next morning (app/Console/Commands/SendRecipientSummaries.php).
Schedule::command('digest:recipients')->dailyAt('07:30')->timezone('Europe/Amsterdam');
Schedule::command('model:prune', ['--model' => [\App\Models\AbuseReport::class, \App\Models\FormClaim::class, \App\Models\OAuthClient::class, \App\Models\OAuthCode::class, \App\Models\OAuthRefreshToken::class]])->daily();
// Signed-in MCP apps get hour-long keys; expired ones are cleared a day later.
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->daily();
// A failed job keeps its payload, which for a submission email is the visitor's answers. Kept a
// week to look into the failure, then deleted, so no copy outlives the form's own retention.
Schedule::command('queue:prune-failed', ['--hours' => 168])->daily();

// Log files go after the days the privacy policy gives for application logs (App\Support\LogRetention).
Schedule::call(fn () => \App\Support\LogRetention::prune())->dailyAt('03:40')->name('logs-prune');
// The database cache never deletes an entry that expired. Rate limits keep visitors' IPs in their
// keys (Laravel hashes them without a key), so expired entries are cleared every day.
Schedule::call(fn () => config('cache.default') === 'database'
    ? \Illuminate\Support\Facades\DB::table(config('cache.stores.database.table', 'cache'))->where('expiration', '<', time())->delete()
    : null)->dailyAt('03:45')->name('cache-expired-prune');

// Scheduler heartbeat: GET /up/scheduler reads this file's age (see routes/web.php).
Schedule::call(fn () => touch(storage_path('framework/scheduler-tick')))->everyMinute()->name('scheduler-tick');
