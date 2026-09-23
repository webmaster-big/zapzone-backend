<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LogApiFailures
{
    public const HEADER = 'X-Request-Id';

    protected const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'access_token',
        'device_token',
        'session_secret',
        'passcode',
        'kiosk_passcode',
        'slideshow_passcode',
        'pin',
        'override_pin',
        'card_number',
        'cvv',
        'card_code',
        'opaque_value',
        'data_value',
        'nonce',
        'signature',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) ($request->header(self::HEADER) ?: Str::uuid());
        $request->attributes->set('request_id', $requestId);
        $startedAt = microtime(true);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        $status = $response->getStatusCode();

        if ($status < 400) {
            return $response;
        }

        $context = [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'ip' => $request->ip(),
            'fields' => $this->fieldNames($request),
        ] + $this->actor($request) + $this->failure($response);

        $status >= 500
            ? Log::channel('api')->error('API request failed', $context)
            : Log::channel('api')->warning('API request refused', $context);

        return $response;
    }

    protected function actor(Request $request): array
    {
        $user = $request->user();

        if (!$user instanceof User) {
            return ['actor' => $user ? 'non-staff token' : 'guest'];
        }

        return [
            'actor' => 'staff',
            'user_id' => $user->id,
            'role' => $user->role,
            'company_id' => $user->company_id,
            'location_id' => $user->location_id,
        ];
    }

    protected function failure(Response $response): array
    {
        $payload = json_decode((string) $response->getContent(), true);

        if (!is_array($payload)) {
            return [];
        }

        $out = [];

        if (isset($payload['message']) && is_string($payload['message'])) {
            $out['message'] = Str::limit($payload['message'], 300);
        }

        if (isset($payload['errors']) && is_array($payload['errors'])) {
            $out['invalid_fields'] = array_keys($payload['errors']);
            $out['first_error'] = Str::limit((string) (reset($payload['errors'])[0] ?? ''), 300);
        }

        return $out;
    }

    protected function fieldNames(Request $request): array
    {
        $keys = array_keys($request->all());

        return array_values(array_filter(
            $keys,
            fn ($key) => !in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)
        ));
    }
}
