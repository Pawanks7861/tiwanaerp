<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep route()/Ziggy URLs on the same host the browser is using.
 * localhost vs 127.0.0.1 are different origins; mismatched stream/PDF iframe
 * URLs are blocked by X-Frame-Options SAMEORIGIN and CSP frame-src 'self'.
 */
class SyncUrlRootFromRequest
{
    /** @var list<string> */
    private const LOCAL_ALIASES = ['localhost', '127.0.0.1', '::1'];

    public function handle(Request $request, Closure $next): Response
    {
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $requestHost = strtolower($request->getHost());

        if ($requestHost === $appHost
            || (in_array($appHost, self::LOCAL_ALIASES, true) && in_array($requestHost, self::LOCAL_ALIASES, true))) {
            URL::forceRootUrl($request->getSchemeAndHttpHost());
        }

        return $next($request);
    }
}
