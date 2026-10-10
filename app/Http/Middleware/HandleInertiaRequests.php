<?php

namespace App\Http\Middleware;

use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Chat\ChatPresenter;
use App\Services\Notifications\FcmClient;
use App\Support\Permissions\DefaultRoles;
use App\Support\Permissions\PermissionCatalog;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Shared props. Company-dependent values are closures: they resolve at render time, after
     * SetCurrentCompany has run (this middleware runs earlier in the stack).
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'app' => ['name' => config('app.name')],
            'features' => [
                'multi_company' => (bool) config('features.multi_company'),
            ],
            'auth' => fn () => $this->auth($request->user()),
            'company' => fn () => $this->company($request->user()),
            'projectSwitcher' => fn () => $this->projects($request->user()),
            'unreadNotifications' => fn () => $this->unreadCount($request->user()),
            'chatUnread' => fn () => $this->chatUnread($request->user()),
            'branding' => fn () => $this->branding(),
            'fcm' => fn () => [
                'configured' => app(FcmClient::class)->configured(),
                'web' => app(FcmClient::class)->webConfig(),
            ],
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function auth(?User $user): ?array
    {
        if ($user === null) {
            return ['user' => null, 'permissions' => []];
        }

        $hasCompany = app(CurrentCompany::class)->has();

        return [
            'user' => [
                ...$user->only(['id', 'name', 'email', 'mobile']),
                'is_super_admin' => $user->isSuperAdmin(),
                'email_verified_at' => $user->email_verified_at,
                'two_factor_enabled' => $user->twoFactorConfirmed(),
            ],
            'two_factor_recommended' => $this->twoFactorRecommended($user),
            'permissions' => match (true) {
                $user->isSuperAdmin() => PermissionCatalog::all(),
                $hasCompany => $user->getAllPermissions()->pluck('name')->values()->all(),
                default => [],
            },
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function company(?User $user): ?array
    {
        $current = app(CurrentCompany::class)->get();
        if ($user === null || $current === null) {
            return null;
        }

        $available = config('features.multi_company')
            ? $user->accessibleCompaniesQuery()->limit(50)->get(['id', 'name', 'code'])
                ->map(fn ($c) => $c->only(['id', 'name', 'code']))->all()
            : [];

        return [
            'current' => $current->only(['id', 'name', 'code', 'state_code']),
            'available' => $available,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function projects(?User $user): array
    {
        if ($user === null || ! app(CurrentCompany::class)->has() || ! $user->can('projects.view')) {
            return [];
        }

        return Project::query()
            ->visibleTo($user)
            ->whereIn('status', ['planning', 'active', 'on_hold'])
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'code', 'name', 'status'])
            ->map(fn (Project $p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name])
            ->all();
    }

    private function unreadCount(?User $user): int
    {
        $companyId = app(CurrentCompany::class)->id();
        if ($user === null || $companyId === null) {
            return 0;
        }

        return $this->scopedUnread($user, $companyId);
    }

    private function chatUnread(?User $user): int
    {
        if ($user === null || ! app(CurrentCompany::class)->has()) {
            return 0;
        }

        return app(ChatPresenter::class)->unreadTotal($user);
    }

    /**
     * @return array{logo_url: ?string, favicon_url: ?string}
     */
    private function branding(): array
    {
        $company = app(CurrentCompany::class)->get();
        if ($company === null) {
            return ['logo_url' => null, 'favicon_url' => null];
        }

        $version = $company->updated_at?->getTimestamp();

        return [
            'logo_url' => $company->logo_path
                ? route('company.branding.show', ['kind' => 'logo', 'v' => $version])
                : null,
            'favicon_url' => $company->favicon_path
                ? route('company.branding.show', ['kind' => 'favicon', 'v' => $version])
                : null,
        ];
    }

    private function twoFactorRecommended(User $user): bool
    {
        if ($user->twoFactorConfirmed()) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        if (! app(CurrentCompany::class)->has()) {
            return false;
        }

        return $user->hasAnyRole([
            DefaultRoles::COMPANY_ADMIN,
            DefaultRoles::DIRECTOR,
            DefaultRoles::ACCOUNTANT,
        ]) || $user->can('tally.manage');
    }

    private function scopedUnread(User $user, int $companyId): int
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->id)
            ->where('company_id', $companyId)
            ->whereNull('read_at')
            ->count();
    }
}
