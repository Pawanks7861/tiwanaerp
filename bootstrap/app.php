<?php

use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureProjectAccess;
use App\Http\Middleware\EnsureTwoFactor;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCurrentCompany;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsureTwoFactor::class,
        ]);

        $middleware->append(SecurityHeaders::class);

        $trustedProxies = array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('TRUSTED_PROXIES', ''))
        )));
        if ($trustedProxies !== []) {
            $middleware->trustProxies(at: $trustedProxies);
        }

        $middleware->alias([
            'company' => SetCurrentCompany::class,
            'feature' => EnsureFeatureEnabled::class,
            'project.access' => EnsureProjectAccess::class,
        ]);

        // Company context must exist before route-model binding resolves company-scoped models.
        $middleware->appendToPriorityList(AuthenticatesRequests::class, SetCurrentCompany::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->dontFlash([
            'password',
            'password_confirmation',
            'current_password',
            'admin_password',
            'two_factor_code',
            'recovery_code',
        ]);
    })->create();
