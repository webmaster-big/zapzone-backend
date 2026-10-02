<?php

namespace App\Http\Middleware;

use App\Models\StaffTerminal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffTerminal
{
    public const ATTRIBUTE = 'staff_terminal';

    public const HEADER = 'X-Staff-Terminal';

    public function handle(Request $request, Closure $next): Response
    {
        $terminal = self::resolve($request);

        if (! $terminal) {
            return response()->json([
                'success' => false,
                'message' => 'This device is not set up as a shared terminal. Please ask a manager to set it up again.',
                'errors' => ['staff_terminal' => ['unregistered']],
            ], 403);
        }

        $request->attributes->set(self::ATTRIBUTE, $terminal);

        return $next($request);
    }

    public static function resolve(Request $request): ?StaffTerminal
    {
        $presented = trim((string) $request->header(self::HEADER, ''));

        if ($presented === '') {
            return null;
        }

        $terminal = StaffTerminal::query()
            ->active()
            ->where('token_hash', hash('sha256', $presented))
            ->first();

        if (! $terminal) {
            return null;
        }

        $maxDays = (int) config('staff_pins.terminal_token_days', 90);

        if ($maxDays > 0 && $terminal->updated_at && $terminal->updated_at->lt(now()->subDays($maxDays))) {
            return null;
        }

        return $terminal;
    }

    public static function fromRequest(Request $request): ?StaffTerminal
    {
        $terminal = $request->attributes->get(self::ATTRIBUTE);

        return $terminal instanceof StaffTerminal ? $terminal : self::resolve($request);
    }
}
