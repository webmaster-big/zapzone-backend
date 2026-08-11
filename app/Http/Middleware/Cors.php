<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Cors
{
    /**
     * Request headers the browser is allowed to send.
     *
     * Kept in ONE place because the preflight reply and the real reply must agree: a header
     * missing here makes the browser refuse the request outright, and the front end sees a
     * network error with no status, which looks like the device being offline.
     */
    public const ALLOWED_HEADERS = [
        'Content-Type',
        'Authorization',
        'X-Requested-With',
        'Accept',
        'Origin',
        'X-CSRF-TOKEN',
        'X-Visitor-Id',
        'X-Session-Id',
        'X-Analytics-Source',
        'X-Tracking-Id',
        // Photo kiosk and slideshow devices identify themselves with these.
        'X-Photo-Device',
        'X-Kiosk-Session',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin');
        $allowedOrigins = [
            'http://localhost:5173',
            'http://127.0.0.1:5173',
            'http://zapzone-backend.test',
            'https://zapzone-backend.test',
            'https://booking.zap-zone.com',
            'https://zapzone-backend-1oulhaj4.on-forge.com',
            'https://zapzone-backend-yt1lm2w5.on-forge.com',
        ];

        $allowedOrigin = in_array($origin, $allowedOrigins) ? $origin : '*';

        if ($request->isMethod('OPTIONS')) {
            return response('', 200)
                ->header('Access-Control-Allow-Origin', $allowedOrigin)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', implode(', ', self::ALLOWED_HEADERS))
                ->header('Access-Control-Allow-Credentials', 'true')
                ->header('Access-Control-Max-Age', '86400');
        }

        $response = $next($request);

        $response->headers->set('Access-Control-Allow-Origin', $allowedOrigin);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', implode(', ', self::ALLOWED_HEADERS));
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }
}
