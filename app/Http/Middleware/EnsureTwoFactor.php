<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Users who have confirmed an authenticator must pass a challenge for this session.
 * Accounts that have not enrolled are not blocked, so existing administrators can sign in and enroll.
 */
class EnsureTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || ! $user->twoFactorConfirmed()) {
            return $next($request);
        }

        if ((int) $request->session()->get('two_factor_passed') === (int) $user->id) {
            return $next($request);
        }

        if ($request->routeIs('two-factor.*', 'logout')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'Two-factor authentication is required.');
        }

        return redirect()->route('two-factor.challenge');
    }
}
