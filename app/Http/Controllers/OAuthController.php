<?php

namespace App\Http\Controllers;

use App\Models\OAuthClient;
use App\Models\OAuthCode;
use App\Models\OAuthRefreshToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A small OAuth 2.1 authorization server for the MCP server, so Claude, ChatGPT and other MCP
 * clients can sign a person in instead of asking for an API key. It issues ordinary Sanctum
 * tokens (ability "mcp:use", one hour) plus rotating refresh tokens. Passport is not used: it
 * cannot share the User model with Sanctum (both define HasApiTokens, with a clashing
 * $accessToken property and withAccessToken() signature).
 *
 * Spec: modelcontextprotocol.io/specification/draft/basic/authorization, and Claude's connector
 * requirements (claude.com/docs/connectors/building/authentication): 401 with resource_metadata,
 * DCR, S256 PKCE, the claude.ai callback, port-agnostic loopback redirects, form-encoded token
 * requests, invalid_grant for a dead refresh token, refresh token rotation.
 */
class OAuthController extends Controller
{
    public const SCOPE = 'mcp:use';

    public const ACCESS_MINUTES = 60;

    public function protectedResource(): JsonResponse
    {
        return response()->json([
            'resource' => self::resource(),
            'authorization_servers' => [self::issuer()],
            'scopes_supported' => [self::SCOPE],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'FormHaven',
            'resource_documentation' => url('/llms.txt'),
        ]);
    }

    public function authorizationServer(): JsonResponse
    {
        return response()->json([
            'issuer' => self::issuer(),
            'authorization_endpoint' => route('oauth.authorize'),
            'token_endpoint' => route('oauth.token'),
            'registration_endpoint' => route('oauth.register'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => [self::SCOPE],
            'authorization_response_iss_parameter_supported' => true,
            'service_documentation' => url('/llms.txt'),
        ]);
    }

    /** RFC 7591 Dynamic Client Registration. Public clients only: there is no client secret. */
    public function register(Request $request): JsonResponse
    {
        $uris = $request->input('redirect_uris');

        if (! is_array($uris) || $uris === [] || count($uris) > 10) {
            return $this->registrationError('invalid_redirect_uri', 'Send redirect_uris as a list of 1 to 10 URLs.');
        }

        foreach ($uris as $uri) {
            if (! is_string($uri) || strlen($uri) > 1024 || ! self::acceptableRedirect($uri)) {
                return $this->registrationError('invalid_redirect_uri', 'Each redirect URI must be an https URL, or http on localhost or 127.0.0.1, with no fragment.');
            }
        }

        $name = trim((string) ($request->input('client_name') ?? 'MCP client'));
        $client = OAuthClient::create([
            'id' => Str::lower(Str::random(32)),
            'name' => Str::limit($name !== '' ? strip_tags($name) : 'MCP client', 100, ''),
            'redirect_uris' => array_values($uris),
        ]);

        return response()->json([
            'client_id' => $client->id,
            'client_id_issued_at' => $client->created_at->timestamp,
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => self::SCOPE,
        ], 201);
    }

    /** The consent screen. A bad client or redirect URI is shown here and never redirected to. */
    public function showAuthorize(Request $request)
    {
        [$client, $error] = $this->checkAuthorizeRequest($request);

        if ($error) {
            return $error;
        }

        $redirectHost = strtolower((string) parse_url($request->query('redirect_uri'), PHP_URL_HOST));
        $loopback = OAuthClient::isLoopback($request->query('redirect_uri'));

        return view('oauth.authorize', [
            'client' => $client,
            'redirectHost' => $redirectHost,
            'loopback' => $loopback,
            // Anyone can register a client under any name, so the name proves nothing. A host we do not
            // know gets a warning naming where the access would go. A loopback address has its own note.
            'unknownHost' => ! $loopback && ! in_array($redirectHost, config('services.oauth.known_redirect_hosts', []), true),
            'params' => $request->only(['client_id', 'redirect_uri', 'response_type', 'state', 'code_challenge', 'code_challenge_method', 'resource', 'scope']),
        ]);
    }

    public function approve(Request $request)
    {
        [$client, $error] = $this->checkAuthorizeRequest($request);

        if ($error) {
            return $error;
        }

        if ($request->input('decision') !== 'allow') {
            return $this->redirectBack($request, ['error' => 'access_denied', 'error_description' => 'The user denied access.']);
        }

        $code = Str::random(64);
        OAuthCode::create([
            'code_hash' => hash('sha256', $code),
            'client_id' => $client->id,
            'user_id' => $request->user()->id,
            'redirect_uri' => $request->input('redirect_uri'),
            'code_challenge' => $request->input('code_challenge'),
            'expires_at' => now()->addSeconds(OAuthCode::LIFETIME),
        ]);

        return $this->redirectBack($request, ['code' => $code]);
    }

    public function token(Request $request): JsonResponse
    {
        return match ($request->input('grant_type')) {
            'authorization_code' => $this->exchangeCode($request),
            'refresh_token' => $this->refresh($request),
            default => $this->tokenError('unsupported_grant_type', 'grant_type must be authorization_code or refresh_token.'),
        };
    }

    private function exchangeCode(Request $request): JsonResponse
    {
        $verifier = (string) $request->input('code_verifier');
        $resource = $request->input('resource');

        if ($resource !== null && ! self::isOurResource((string) $resource)) {
            return $this->tokenError('invalid_target', 'resource must be '.self::resource().'.');
        }

        return DB::transaction(function () use ($request, $verifier) {
            $code = OAuthCode::where('code_hash', hash('sha256', (string) $request->input('code')))->lockForUpdate()->first();

            if (! $code || $code->client_id !== (string) $request->input('client_id') || $code->redirect_uri !== (string) $request->input('redirect_uri')) {
                return $this->tokenError('invalid_grant', 'The code is unknown, or was issued to another client or redirect URI.');
            }

            // A code used twice means it leaked: revoke everything issued from it (OAuth 2.1 section 4.1.3).
            if ($code->used_at) {
                $this->revokeFromCode($code);

                return $this->tokenError('invalid_grant', 'The code has already been used.');
            }

            if ($code->expires_at->isPast()) {
                return $this->tokenError('invalid_grant', 'The code has expired. Sign in again.');
            }

            $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if (strlen($verifier) < 43 || strlen($verifier) > 128 || ! hash_equals($code->code_challenge, $expected)) {
                return $this->tokenError('invalid_grant', 'code_verifier does not match the code_challenge.');
            }

            $code->forceFill(['used_at' => now()])->save();
            $client = OAuthClient::find($code->client_id);
            $user = User::find($code->user_id);

            if (! $client || ! $user) {
                return $this->tokenError('invalid_grant', 'The client or the account no longer exists.');
            }

            return $this->issue($user, $client, $code);
        });
    }

    private function refresh(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $old = OAuthRefreshToken::where('token_hash', hash('sha256', (string) $request->input('refresh_token')))->lockForUpdate()->first();

            if (! $old || $old->client_id !== (string) $request->input('client_id')) {
                return $this->tokenError('invalid_grant', 'The refresh token is unknown or was issued to another client.');
            }

            // A rotated token used again means it leaked: end that client's sign-in for this account.
            if ($old->revoked_at) {
                $this->revokeClientSession($old->client_id, $old->user_id);

                return $this->tokenError('invalid_grant', 'The refresh token has already been used. Sign in again.');
            }

            if ($old->expires_at->isPast()) {
                return $this->tokenError('invalid_grant', 'The refresh token has expired. Sign in again.');
            }

            $client = OAuthClient::find($old->client_id);
            $user = User::find($old->user_id);

            if (! $client || ! $user) {
                return $this->tokenError('invalid_grant', 'The client or the account no longer exists.');
            }

            $old->forceFill(['revoked_at' => now()])->save();
            $user->tokens()->whereKey($old->access_token_id)->delete();

            return $this->issue($user, $client, null, $old->code_id);
        });
    }

    private function issue(User $user, OAuthClient $client, ?OAuthCode $code, ?int $codeId = null): JsonResponse
    {
        $access = $user->createToken(Str::limit($client->name.' (signed in)', 255, ''), [self::SCOPE], now()->addMinutes(self::ACCESS_MINUTES));
        $refresh = Str::random(64);

        OAuthRefreshToken::create([
            'token_hash' => hash('sha256', $refresh),
            'client_id' => $client->id,
            'user_id' => $user->id,
            'access_token_id' => $access->accessToken->id,
            'code_id' => $code?->id ?? $codeId,
            'expires_at' => now()->addDays(OAuthRefreshToken::DAYS),
        ]);

        if ($code) {
            $code->forceFill(['access_token_id' => $access->accessToken->id])->save();
        }

        return response()->json([
            'access_token' => $access->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_MINUTES * 60,
            'refresh_token' => $refresh,
            'scope' => self::SCOPE,
        ])->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }

    private function revokeFromCode(OAuthCode $code): void
    {
        $tokens = OAuthRefreshToken::where('code_id', $code->id);
        $accessIds = (clone $tokens)->pluck('access_token_id')->push($code->access_token_id)->filter();
        $tokens->update(['revoked_at' => now()]);
        \Laravel\Sanctum\PersonalAccessToken::whereIn('id', $accessIds)->delete();
    }

    private function revokeClientSession(string $clientId, int $userId): void
    {
        $tokens = OAuthRefreshToken::where('client_id', $clientId)->where('user_id', $userId);
        \Laravel\Sanctum\PersonalAccessToken::whereIn('id', (clone $tokens)->pluck('access_token_id')->filter())->delete();
        $tokens->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /** @return array{0: ?OAuthClient, 1: mixed} the client, or an error response to return */
    private function checkAuthorizeRequest(Request $request): array
    {
        $client = OAuthClient::find((string) $request->input('client_id'));
        $redirect = (string) $request->input('redirect_uri');

        if (! $client || $redirect === '' || ! $client->allowsRedirect($redirect)) {
            return [null, response()->view('oauth.error', ['message' => 'This sign-in link is not valid: the app is unknown, or the address it wants to send you back to was not registered. Start the connection again from the app.'], 400)];
        }

        $problem = match (true) {
            $request->input('response_type') !== 'code' => ['unsupported_response_type', 'response_type must be code.'],
            blank($request->input('code_challenge')) || strlen((string) $request->input('code_challenge')) > 128 => ['invalid_request', 'A PKCE code_challenge is required.'],
            $request->input('code_challenge_method') !== 'S256' => ['invalid_request', 'code_challenge_method must be S256.'],
            $request->filled('resource') && ! self::isOurResource((string) $request->input('resource')) => ['invalid_target', 'resource must be '.self::resource().'.'],
            default => null,
        };

        return $problem ? [$client, $this->redirectBack($request, ['error' => $problem[0], 'error_description' => $problem[1]])] : [$client, null];
    }

    private function redirectBack(Request $request, array $params)
    {
        $redirect = (string) $request->input('redirect_uri');
        $params += array_filter(['state' => $request->input('state')], fn ($v) => $v !== null);
        $params['iss'] = self::issuer();

        return redirect()->away($redirect.(str_contains($redirect, '?') ? '&' : '?').http_build_query($params));
    }

    private function tokenError(string $error, string $description): JsonResponse
    {
        return response()->json(['error' => $error, 'error_description' => $description], 400)->header('Cache-Control', 'no-store');
    }

    private function registrationError(string $error, string $description): JsonResponse
    {
        return response()->json(['error' => $error, 'error_description' => $description], 400);
    }

    public static function issuer(): string
    {
        return rtrim(url('/'), '/');
    }

    public static function resource(): string
    {
        return url('/mcp');
    }

    /** The MCP URL, compared without case in scheme and host and without a trailing slash. */
    private static function isOurResource(string $resource): bool
    {
        $normal = fn (string $uri) => rtrim(preg_replace_callback('#^[a-z]+://[^/]+#i', fn ($m) => strtolower($m[0]), $uri), '/');

        return in_array($normal($resource), [$normal(self::resource()), $normal(self::issuer())], true);
    }

    private static function acceptableRedirect(string $uri): bool
    {
        $parts = parse_url($uri);

        if ($parts === false || isset($parts['fragment']) || ! isset($parts['scheme'], $parts['host']) || ! OAuthClient::unambiguous($uri)) {
            return false;
        }

        return $parts['scheme'] === 'https' || OAuthClient::isLoopback($uri);
    }
}
