<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Permissions\PermissionCatalog;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = User::query()->where('email', strtolower($credentials['email']))->first();

        if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        $token = $user->createToken($credentials['device_name'])->plainTextToken;

        $companies = $user->accessibleCompaniesQuery()->get(['id', 'name', 'code'])
            ->map(fn ($c) => $c->only(['id', 'name', 'code']));

        if (! config('features.multi_company')) {
            $active = $companies->firstWhere('id', $user->current_company_id) ?? $companies->first();
            $companies = $active ? collect([$active]) : collect();
        }

        return response()->json([
            'token' => $token,
            'user' => $user->only(['id', 'name', 'email', 'mobile']),
            'companies' => $companies->values(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request, CurrentCompany $tenancy): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $user->only(['id', 'name', 'email', 'mobile']),
            'company' => $tenancy->require()->only(['id', 'name', 'code']),
            'permissions' => $user->isSuperAdmin()
                ? PermissionCatalog::all()
                : $user->getAllPermissions()->pluck('name')->values(),
        ]);
    }
}
