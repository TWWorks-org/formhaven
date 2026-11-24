<?php

namespace App\Support;

use App\Actions\StartForm;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The 401 an agent gets when the setup key from POST /api/v1/start has run out
 * (config sanctum.setup_key_days), with what to do next. Any other failed login keeps
 * the framework's own answer: null.
 */
class ExpiredSetupKey
{
    public const CODE = 'SETUP_KEY_EXPIRED';

    public static function response(Request $request): ?JsonResponse
    {
        $bearer = (string) $request->bearerToken();
        $token = $bearer === '' ? null : PersonalAccessToken::findToken($bearer);

        if (! $token || ! $token->expires_at?->isPast() || ! self::isSetupKey($token)) {
            return null;
        }

        return response()->json([
            'message' => self::message($token->expires_at),
            'code' => self::CODE,
            'api_keys' => route('api-keys'),
            'docs' => url('/llms.txt'),
        ], 401);
    }

    public static function message(\DateTimeInterface $expiredAt): string
    {
        return 'This setup key expired on '.$expiredAt->format('Y-m-d').'. A setup key works for '.StartForm::setupKeyDays().' days after the site owner activates the form. '
            .'To go on, ask the site owner to create an API key on the API keys page ('.route('api-keys').') and send that as "Authorization: Bearer <key>" on the REST API and the MCP server.';
    }

    /** Only setup keys reach one form ("form:<id>"); dashboard keys have "*" and signed-in apps "mcp:use". */
    public static function isSetupKey(PersonalAccessToken $token): bool
    {
        return collect($token->abilities ?? [])->contains(fn ($ability) => str_starts_with((string) $ability, User::FORM_ABILITY));
    }
}
