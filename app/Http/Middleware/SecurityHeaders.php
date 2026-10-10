<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser protections. HSTS is sent only for production HTTPS so local HTTP keeps working.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Content-Security-Policy', $this->policy($request));

        if (app()->isProduction() && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function policy(Request $request): string
    {
        $script = ["'self'", "'unsafe-inline'", "'wasm-unsafe-eval'"];
        $style = ["'self'", "'unsafe-inline'", 'https://fonts.bunny.net'];
        $font = ["'self'", 'https://fonts.bunny.net', 'data:'];
        $connect = ["'self'"];
        $img = ["'self'", 'data:', 'blob:'];

        if (app()->environment('local')) {
            $vite = 'http://127.0.0.1:5173';
            $script[] = $vite;
            $style[] = $vite;
            $connect[] = $vite;
            $connect[] = 'ws://127.0.0.1:5173';
            $connect[] = 'http://localhost:5173';
            $connect[] = 'ws://localhost:5173';
        }

        $directives = [
            "default-src 'self'",
            'script-src '.implode(' ', $script),
            'style-src '.implode(' ', $style),
            'img-src '.implode(' ', $img),
            'font-src '.implode(' ', $font),
            'connect-src '.implode(' ', $connect),
            "worker-src 'self'",
            "frame-src 'self'",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ];

        return implode('; ', $directives);
    }
}
