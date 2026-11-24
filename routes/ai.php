<?php

use App\Mcp\Servers\FormHavenServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

// Same API key as the REST API: Authorization: Bearer <key>.
Mcp::web('/mcp', FormHavenServer::class)->middleware(['auth:sanctum', 'throttle:api']);

// Replaces the package's empty 405, so a person or agent opening the URL learns how to connect.
Route::get('/mcp', fn () => response()->json([
    'message' => 'This is the FormHaven MCP server. MCP clients connect with POST; opening it in a browser shows this note.',
    'connect' => [
        'url' => url('/mcp'),
        'header' => 'Authorization: Bearer YOUR_API_KEY',
        'api_key' => route('api-keys'),
        'claude_code' => 'claude mcp add --transport http formhaven '.url('/mcp').' --header "Authorization: Bearer YOUR_API_KEY"',
        'no_key_yet' => 'POST '.url('/api/v1/start').' reserves a form without an account; its setup_key works here once the site owner activates the form, for '.\App\Actions\StartForm::setupKeyDays().' days.',
        'oauth' => 'Clients that support OAuth sign in with just this URL: metadata at '.url('/.well-known/oauth-protected-resource/mcp').'.',
    ],
    'docs' => url('/llms.txt'),
], 405)->header('Allow', 'POST'));
