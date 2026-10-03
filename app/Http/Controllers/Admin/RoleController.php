<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoleRequest;
use App\Models\Core\Role;
use App\Support\Permissions\PermissionCatalog;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Role::class);

        $roles = Role::query()->forCurrentCompany()->withCount('permissions')->orderByDesc('is_system')->orderBy('name')->get();
        $userCounts = DB::table(config('permission.table_names.model_has_roles'))
            ->where(config('permission.column_names.team_foreign_key'), $this->tenancy->require()->id)
            ->whereIn('role_id', $roles->pluck('id'))
            ->groupBy('role_id')
            ->pluck(DB::raw('count(*)'), 'role_id');

        $user = $request->user();

        return Inertia::render('Admin/Roles/Index', [
            'roles' => $roles->map(fn (Role $r) => [
                ...$r->only(['id', 'name', 'description', 'is_system', 'permissions_count']),
                'users_count' => (int) ($userCounts[$r->id] ?? 0),
                'can_update' => $user->can('update', $r),
                'can_delete' => $user->can('delete', $r),
            ])->all(),
            'can' => ['create' => $user->can('create', Role::class)],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Role::class);

        return Inertia::render('Admin/Roles/Form', ['role' => null, 'catalog' => $this->catalog()]);
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $role = Role::query()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'guard_name' => 'web',
            'team_id' => $this->tenancy->require()->id,
            'is_system' => false,
        ]);
        $role->syncPermissionNames($validated['permissions']);

        return redirect()->route('admin.roles.index')->with('success', "Role {$role->name} created.");
    }

    public function edit(Request $request, Role $role): Response
    {
        Gate::authorize('view', $role);

        return Inertia::render('Admin/Roles/Form', [
            'role' => [
                ...$role->only(['id', 'name', 'description', 'is_system']),
                'permissions' => $role->permissions()->pluck('name')->all(),
                'readonly' => ! $request->user()->can('update', $role),
            ],
            'catalog' => $this->catalog(),
        ]);
    }

    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        $validated = $request->validated();

        $role->fill(['description' => $validated['description'] ?? null]);
        if (! $role->is_system) {
            $role->name = $validated['name'];
        }
        $role->save();
        $role->syncPermissionNames($validated['permissions']);

        return redirect()->route('admin.roles.index')->with('success', "Role {$role->name} updated.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        Gate::authorize('delete', $role);

        $assigned = DB::table(config('permission.table_names.model_has_roles'))->where('role_id', $role->id)->exists();
        if ($assigned) {
            throw ValidationException::withMessages(['role' => 'This role is assigned to users. Remove it from all users first.']);
        }

        $role->delete();
        $this->registrar->forgetCachedPermissions();

        return redirect()->route('admin.roles.index')->with('success', 'Role deleted.');
    }

    /**
     * @return list<array{group: string, modules: list<array{key: string, label: string, actions: list<string>}>}>
     */
    private function catalog(): array
    {
        $groups = [];
        foreach (PermissionCatalog::modules() as $key => $module) {
            $groups[$module['group']][] = ['key' => $key, 'label' => $module['label'], 'actions' => $module['actions']];
        }

        return array_map(fn ($group, $modules) => ['group' => $group, 'modules' => $modules], array_keys($groups), $groups);
    }
}
