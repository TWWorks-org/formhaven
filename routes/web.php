<?php

use App\Http\Controllers\ContentController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\SubmitController;
use App\Support\Mode;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

/*
 * Scheduler heartbeat. The every-minute 'scheduler-tick' in routes/console.php
 * touches a file; this answers 503 once that file is over ten minutes old, so
 * a dead cron shows up from outside even while every page still loads.
 * Point an external uptime monitor at it: any 5xx means the scheduler stopped.
 */
Route::get('/up/scheduler', function () {
    $file = storage_path('framework/scheduler-tick');
    clearstatcache(true, $file);
    $tick = is_file($file) ? filemtime($file) : false;
    $age = $tick === false ? null : max(0, time() - $tick);
    $ok = $age !== null && $age <= 600;

    return response()->json(['ok' => $ok, 'last_tick_seconds_ago' => $age], $ok ? 200 : 503)
        ->header('Cache-Control', 'no-store');
})->name('up.scheduler');

if (Mode::hosted()) {
    Route::get('/', function () {
        return view('welcome');
    })->name('home');
} else {
    // A self-hosted install has no marketing site: its address opens the dashboard, or the login page.
    Route::get('/', fn () => redirect()->route(auth()->check() ? 'forms.index' : 'login'))->name('home');
}

// Posts come from other sites, so the browser sends no session cookie. With the
// session middleware on, every submission started a fresh session and its
// Set-Cookie overwrote a logged-in owner's cookie: testing your own form logged
// you out (found live, 2026-10-27), and each submission wrote a sessions row.
Route::post('f/{publicId}', SubmitController::class)
    ->withoutMiddleware([
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ])
    ->middleware('throttle:submit')
    ->name('submit');
// The bot-check puzzle /bot-check.js solves before a form sends. Cookieless, like the endpoint itself.
Route::get('f/{publicId}/challenge', function (string $publicId) {
    $form = \App\Models\Form::where('public_id', $publicId)->first();

    return $form
        ? response()->json(\App\Support\BotCheck::challenge($form))->header('Cache-Control', 'no-store')
        : response()->json(['message' => 'No form exists at this address. Check the form ID in your HTML action.'], 404);
})
    ->withoutMiddleware([
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ])
    ->middleware('throttle:60,1')
    ->name('submit.challenge');
// The home page's live demo form: hosted only, like the page.
if (Mode::hosted()) {
    Route::post('demo', DemoController::class)
        ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
        ->middleware('throttle:10,1')
        ->name('demo');
}
Route::get('f/thanks', fn (\Illuminate\Http\Request $request) => view('submit-thanks', [
    // The link back comes from the site the form's owner saved, never from the URL,
    // and only through the signed, 10-minute link a real submission gets: a bare
    // ?form= would let anyone share "Back to <a site they saved>" on our domain.
    'back' => $request->hasValidSignature()
        ? \App\Models\Form::where('public_id', (string) $request->query('form'))->first()?->siteOrigin()
        : null,
]))->name('submit.thanks');
// The links in a recipient's emails. Signed, so only the address we wrote to can use them; the controller checks the signature.
Route::match(['get', 'post'], 'recipients/{recipient}/confirm', [\App\Http\Controllers\RecipientController::class, 'confirm'])
    ->middleware('throttle:30,1')
    ->whereNumber('recipient')
    ->name('recipients.confirm');
// Mail apps send the List-Unsubscribe one-click POST with no session, so no CSRF token; the signature is the check.
Route::match(['get', 'post'], 'recipients/{recipient}/stop', [\App\Http\Controllers\RecipientController::class, 'stop'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->middleware('throttle:30,1')
    ->whereNumber('recipient')
    ->name('recipients.stop');
// "Stop the weekly count" in a recipient's weekly summary: only that email stops. Same rules as the stop link above.
Route::match(['get', 'post'], 'recipients/{recipient}/summary/stop', [\App\Http\Controllers\RecipientController::class, 'stopSummary'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->middleware('throttle:30,1')
    ->whereNumber('recipient')
    ->name('recipients.summary.stop');
// A recipient's read-only page of the form's messages, linked from their emails when the owner turned it on.
// Signed like the stop link; no session or cookies, since it only reads. Paging adds ?page=, which the signature ignores.
Route::get('recipients/{recipient}/messages', [\App\Http\Controllers\RecipientController::class, 'messages'])
    ->withoutMiddleware([
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ])
    ->middleware('throttle:recipient-messages')
    ->whereNumber('recipient')
    ->name('recipients.messages');
// "Turn it off" in the Monday summary; same rules as the recipients' stop link above.
Route::match(['get', 'post'], 'digest/{user}/stop', [\App\Http\Controllers\DigestController::class, 'stop'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->middleware('throttle:30,1')
    ->whereNumber('user')
    ->name('digest.stop');
// "Block this sender" in the owner's email about a message; same rules as the stop links above.
Route::match(['get', 'post'], 'submissions/{submission}/block', \App\Http\Controllers\BlockSenderController::class)
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->middleware('throttle:30,1')
    ->whereNumber('submission')
    ->name('submissions.block');
// OAuth sign-in for the MCP server (app/Http/Controllers/OAuthController.php). Discovery, registration
// and the token endpoint are called by apps, not browsers: no session, no cookies, no CSRF token.
$stateless = [
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
];
Route::withoutMiddleware($stateless)->group(function () {
    Route::get('.well-known/oauth-protected-resource', [\App\Http\Controllers\OAuthController::class, 'protectedResource'])->name('mcp.oauth.protected-resource');
    // The name is the one laravel/mcp looks for to point its 401 header here.
    Route::get('.well-known/oauth-protected-resource/{path}', [\App\Http\Controllers\OAuthController::class, 'protectedResource'])->where('path', '.*')->name('mcp.oauth.protected-resource.nested');
    Route::get('.well-known/oauth-authorization-server', [\App\Http\Controllers\OAuthController::class, 'authorizationServer'])->name('oauth.metadata');
    Route::get('.well-known/oauth-authorization-server/{path}', [\App\Http\Controllers\OAuthController::class, 'authorizationServer'])->where('path', '.*');
    Route::post('oauth/register', [\App\Http\Controllers\OAuthController::class, 'register'])->middleware('throttle:20,60')->name('oauth.register');
    Route::post('oauth/token', [\App\Http\Controllers\OAuthController::class, 'token'])->middleware('throttle:60,1')->name('oauth.token');
});
Route::get('oauth/authorize', [\App\Http\Controllers\OAuthController::class, 'showAuthorize'])->middleware(['auth', 'verified', 'throttle:30,1'])->name('oauth.authorize');
Route::post('oauth/authorize', [\App\Http\Controllers\OAuthController::class, 'approve'])->middleware(['auth', 'verified', 'throttle:30,1'])->name('oauth.approve');

// A site owner switches on a form an agent reserved through POST /api/v1/start.
Route::get('claim/{token}', [\App\Http\Controllers\ClaimController::class, 'show'])->middleware('throttle:30,1')->name('claim.show');
// A verified address, like the dashboard: the setup key it creates can add recipients, and their
// confirmation emails must come from an account whose address is proven. The page explains first.
Route::post('claim/{token}', [\App\Http\Controllers\ClaimController::class, 'store'])->middleware(['auth', 'verified', 'throttle:30,1'])->name('claim.store');
// The marketing site: hosted only. A self-hosted install answers 404 on all of it.
if (Mode::hosted()) {
    Route::view('pricing', 'pricing')->name('pricing');
    Route::get('templates', [ContentController::class, 'templateIndex'])->name('templates.index');
    Route::get('templates/{slug}', [ContentController::class, 'template'])->name('templates.show');
    Route::get('alternatives/{slug}', [ContentController::class, 'alternative'])->name('alternatives.show');
    Route::get('guides', [ContentController::class, 'guideIndex'])->name('guides.index');
    Route::get('guides/{slug}', [ContentController::class, 'guide'])->name('guides.show');
}
Route::get('llms.txt', fn () => response()->view('agents.llms')->header('Content-Type', 'text/plain; charset=utf-8'))->name('llms');
// Rendered from config so the domain and contact address are not hard-coded in public/.
Route::get('robots.txt', fn () => response()->view('agents.robots')->header('Content-Type', 'text/plain; charset=utf-8'))->name('robots');
Route::get('.well-known/security.txt', fn () => response()->view('agents.security')->header('Content-Type', 'text/plain; charset=utf-8'))->name('security-txt');
// IndexNow key file (config/services.php): only the configured key answers, and only when one is set.
Route::get('{indexnowKey}.txt', function (string $indexnowKey) {
    $key = trim((string) config('services.indexnow.key'));
    abort_unless($key !== '' && hash_equals($key, $indexnowKey), 404);

    return response($key)->header('Content-Type', 'text/plain; charset=utf-8');
})->where('indexnowKey', '[a-zA-Z0-9-]{8,128}')->name('indexnow-key');
// The operator's own notice channel and legal pages: hosted only. The report page is the hosted
// service's DSA art. 16 channel (its copy, the admin page and the operator's inbox); a self-hosted
// install is its owner's private tool and has no operator policy to show.
if (Mode::hosted()) {
    // DSA art. 16: anyone can report an abusive form. Two emails per report, so it is throttled hard.
    Route::get('report', [\App\Http\Controllers\AbuseReportController::class, 'create'])->name('report');
    Route::post('report', [\App\Http\Controllers\AbuseReportController::class, 'store'])
        ->middleware('throttle:10,60')
        ->name('report.store');
    Route::get('legal/{page}', LegalController::class)
        ->whereIn('page', array_keys(LegalController::PAGES))
        ->name('legal');
}

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', 'forms')->name('dashboard');
    Volt::route('forms', 'forms.index')->name('forms.index');
    Volt::route('forms/{form}', 'forms.show')->name('forms.show');
    Route::get('forms/{form}/export', \App\Http\Controllers\ExportSubmissionsController::class)->name('forms.export');
    Volt::route('api-keys', 'api-keys')->name('api-keys');
    // Owner accounts only (OWNER_EMAILS); anyone else gets a 404 from the page itself.
    Volt::route('admin', 'admin')->name('admin');
});

// Billing stays reachable after the trial ends (no 'verified' needed), otherwise nobody could pay.
// Hosted only: a self-hosted install has no bill.
if (Mode::hosted()) {
    Route::middleware(['auth'])->group(function () {
        Volt::route('billing', 'billing')->name('billing');
    });
}

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');
    // The emailed link that deletes an account without a password (made through Google). Signed, checked in the controller.
    Route::match(['get', 'post'], 'account/delete/{user}', \App\Http\Controllers\AccountDeletionController::class)
        ->middleware('throttle:10,1')
        ->whereNumber('user')
        ->name('account.delete');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';
