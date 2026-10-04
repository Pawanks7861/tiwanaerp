<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hides a route while its feature flag is off. The controllers and data stay.
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! config('features.'.$feature)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $next($request);
    }
}
