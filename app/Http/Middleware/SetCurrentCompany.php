<?php

namespace App\Http\Middleware;

use App\Models\Core\Company;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active company from authenticated context only (session / user record for web,
 * X-Company-Id header for the API), verifies membership, and scopes tenancy + Spatie roles to it.
 * Runs before route-model binding so bound models are already company-scoped.
 */
class SetCurrentCompany
{
    public const SESSION_KEY = 'current_company_id';

    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        if (! $user->is_active) {
            return $this->deny($request, 'Your account has been deactivated.', logout: true);
        }

        $company = $this->resolve($request, $user);
        if ($company === null) {
            return $this->deny($request, 'Your account is not linked to any active company.');
        }

        $this->tenancy->set($company);
        $this->registrar->setPermissionsTeamId($company->id);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $next($request);
    }

    private function resolve(Request $request, User $user): ?Company
    {
        $isApi = $request->is('api/*');

        if (! config('features.multi_company')) {
            return $this->resolveActiveCompany($request, $user, $isApi);
        }

        $requestedId = $isApi
            ? ($request->header('X-Company-Id') ?: $user->current_company_id)
            : ($request->session()->get(self::SESSION_KEY) ?: $user->current_company_id);

        if ($requestedId && ctype_digit((string) $requestedId)) {
            $company = $user->accessibleCompaniesQuery()->whereKey((int) $requestedId)->first();
            if ($company) {
                $this->remember($request, $user, $company, $isApi);

                return $company;
            }

            // An explicit API header for a company the user cannot access is an error, not a fallback.
            if ($isApi && $request->hasHeader('X-Company-Id')) {
                return null;
            }
        }

        $company = $user->accessibleCompaniesQuery()->first();
        if ($company) {
            $this->remember($request, $user, $company, $isApi);
        }

        return $company;
    }

    /**
     * One company for this sign-in: the stored active company when it is still valid,
     * otherwise the first active membership (companies are ordered by name).
     */
    private function resolveActiveCompany(Request $request, User $user, bool $isApi): ?Company
    {
        $company = null;

        if ($user->current_company_id) {
            $company = $user->accessibleCompaniesQuery()->whereKey($user->current_company_id)->first();
        }

        $company ??= $user->accessibleCompaniesQuery()->first();

        if ($company) {
            $this->remember($request, $user, $company, $isApi);
        }

        return $company;
    }

    private function remember(Request $request, User $user, Company $company, bool $isApi): void
    {
        if ($isApi) {
            return;
        }

        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $company->id);
        }

        if ($user->current_company_id !== $company->id) {
            $user->forceFill(['current_company_id' => $company->id])->saveQuietly();
        }
    }

    private function deny(Request $request, string $message, bool $logout = false): Response
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        if ($logout) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => $message]);
        }

        abort(Response::HTTP_FORBIDDEN, $message);
    }
}
