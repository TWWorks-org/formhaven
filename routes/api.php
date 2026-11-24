<?php

use App\Http\Controllers\Api\FormController;
use App\Http\Controllers\Api\StartController;
use Illuminate\Support\Facades\Route;

// The agent start flow needs no account: it reserves a form address for the site owner to activate.
Route::post('v1/start', [StartController::class, 'store'])->middleware('throttle:start');
Route::get('v1/start/{id}', [StartController::class, 'show'])->middleware('throttle:api');

Route::prefix('v1')->middleware(['auth:sanctum', \App\Http\Middleware\RefuseMcpOnlyTokens::class, 'throttle:api'])->group(function () {
    Route::get('forms', [FormController::class, 'index']);
    Route::post('forms', [FormController::class, 'store']);
    Route::get('forms/{id}', [FormController::class, 'show']);
    Route::get('forms/{id}/submissions', [FormController::class, 'submissions']);
    Route::get('forms/{id}/privacy-text', [FormController::class, 'privacyText']);
    Route::patch('forms/{id}', [FormController::class, 'update']);
    Route::delete('forms/{id}', [FormController::class, 'destroy']);
    Route::post('forms/{id}/recipients', [FormController::class, 'addRecipient']);
    Route::post('forms/{id}/recipients/{email}/resend', [FormController::class, 'resendConfirmation']);
    Route::delete('forms/{id}/recipients/{email}', [FormController::class, 'removeRecipient']);
    Route::post('forms/{id}/deliveries/retry', [FormController::class, 'retryDeliveries']);
});

// Every route above, as an agent reads it here and in llms.txt. A test fails when a route is missing from the list.
$endpoints = array_keys(\App\Support\ApiIndex::ENDPOINTS);

// The fallback below needs a segment after /api/, so the bare /api gets its own index.
Route::get('/', fn () => response()->json([
    'message' => 'FormHaven REST API. Send an API key from the dashboard as Authorization: Bearer KEY. No key yet? POST /api/v1/start reserves a form without one.',
    'endpoints' => $endpoints,
    'mcp' => url('/mcp'),
    'api_key' => route('api-keys'),
    'docs' => url('/llms.txt'),
]));

// Agents get JSON, never an HTML page, for a path that does not exist.
Route::fallback(fn () => response()->json([
    'message' => 'No API endpoint at this path.',
    'endpoints' => $endpoints,
    'docs' => url('/llms.txt'),
], 404));
