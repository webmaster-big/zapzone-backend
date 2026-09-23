<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\LogApiFailures;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ClientErrorController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:500',
            'kind' => 'nullable|string|max:40',
            'page' => 'nullable|string|max:300',
            'action' => 'nullable|string|max:200',
            'status' => 'nullable|integer',
            'request_id' => 'nullable|string|max:64',
            'stack' => 'nullable|string|max:2000',
            'app_version' => 'nullable|string|max:60',
        ]);

        $user = $request->user();

        Log::channel('api')->warning('Frontend error', [
            'source' => 'frontend',
            'kind' => $validated['kind'] ?? 'unknown',
            'message' => Str::limit($validated['message'], 500),
            'page' => isset($validated['page']) ? $this->withoutQuery($validated['page']) : null,
            'action' => isset($validated['action']) ? $this->withoutQuery($validated['action']) : null,
            'status' => $validated['status'] ?? null,
            'request_id' => $validated['request_id'] ?? $request->attributes->get('request_id'),
            'stack' => isset($validated['stack']) ? Str::limit($validated['stack'], 2000) : null,
            'app_version' => $validated['app_version'] ?? null,
            'user_id' => $user instanceof User ? $user->id : null,
            'role' => $user instanceof User ? $user->role : null,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 200),
        ]);

        return $this->accepted($request);
    }

    protected function withoutQuery(string $value): string
    {
        $value = preg_replace('/[?#].*$/', '', $value) ?? $value;

        return Str::limit(trim($value), 200, '');
    }

    protected function accepted(Request $request): JsonResponse
    {
        return response()->json(['success' => true], 202)
            ->header(LogApiFailures::HEADER, (string) $request->attributes->get('request_id'));
    }
}
