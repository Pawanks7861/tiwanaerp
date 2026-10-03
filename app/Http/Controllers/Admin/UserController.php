<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Models\Core\CompanyUser;
use App\Models\Core\Role;
use App\Models\User;
use App\Services\Core\CompanyDirectory;
use App\Services\Core\UserService;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(
        private readonly UserService $users,
        private readonly CompanyDirectory $directory,
        private readonly CurrentCompany $tenancy,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $companyId = $this->tenancy->require()->id;
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : 'all',
        ];

        $users = $this->directory->membersQuery(activeOnly: false)
            ->with(['roles:id,name', 'memberships' => fn ($m) => $m->where('company_id', $companyId)])
            ->when($filters['search'] !== '', function ($q) use ($filters) {
                $term = '%'.addcslashes($filters['search'], '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('mobile', 'like', $term));
            })
            ->when($filters['status'] !== 'all', function ($q) use ($filters, $companyId) {
                $active = $filters['status'] === 'active';
                $q->whereHas('memberships', fn ($m) => $m->where('company_id', $companyId)->where('is_active', $active));
            })
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $u) => $this->row($u));

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => $filters,
            'can' => ['create' => $request->user()->can('create', User::class)],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('Admin/Users/Form', [
            'user' => null,
            'roles' => $this->roleOptions(),
            'canEditProfile' => true,
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $result = $this->users->addToCompany($request->validated(), $request->user());

        $message = $result['existing']
            ? "{$result['user']->name} already had an account and has been added to this company."
            : "User {$result['user']->name} created.";

        return redirect()->route('admin.users.index')->with('success', $message);
    }

    public function edit(Request $request, User $user): Response
    {
        Gate::authorize('update', $user);

        $user->load('roles:id,name');

        return Inertia::render('Admin/Users/Form', [
            'user' => [
                ...$user->only(['id', 'name', 'email', 'mobile']),
                'roles' => $user->roles->pluck('id')->all(),
            ],
            'roles' => $this->roleOptions(),
            'canEditProfile' => $this->users->canEditProfile($user, $request->user()),
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $this->users->update($user, $request->validated(), $request->user());

        return redirect()->route('admin.users.index')->with('success', "User {$user->name} updated.");
    }

    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('toggleActive', $user);

        $active = $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        $this->users->setActive($user, (bool) $active);

        return back()->with('success', $active ? "{$user->name} activated." : "{$user->name} deactivated.");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function roleOptions(): array
    {
        return Role::query()->forCurrentCompany()->orderBy('name')->get(['id', 'name', 'description'])
            ->map(fn (Role $r) => ['value' => $r->id, 'label' => $r->name, 'description' => $r->description])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(User $user): array
    {
        /** @var CompanyUser|null $membership */
        $membership = $user->memberships->first();

        return [
            ...$user->only(['id', 'name', 'email', 'mobile']),
            'roles' => $user->roles->pluck('name')->all(),
            'is_active' => (bool) $membership?->is_active && $user->is_active,
            'account_disabled' => ! $user->is_active,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];
    }
}
