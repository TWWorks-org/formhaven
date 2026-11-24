<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * A token from the OAuth sign-in was issued for the MCP server only (ability "mcp:use"), and the
 * MCP authorization spec says a server must accept only tokens issued for it. So the REST API
 * refuses it and points at the right place.
 */
class RefuseMcpOnlyTokens
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken && ($token->abilities ?? []) === ['mcp:use']) {
            return response()->json([
                'message' => 'This token is for the MCP server at '.url('/mcp').'. For the REST API, create an API key on the API keys page.',
            ], 403);
        }

        return $next($request);
    }
}
