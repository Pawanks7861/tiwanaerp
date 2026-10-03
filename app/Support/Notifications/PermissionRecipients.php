<?php

namespace App\Support\Notifications;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Notification recipients by permission (never by role name): active members of a company whose
 * roles in that company grant any of the permissions. Safe outside a request (no team context needed).
 */
class PermissionRecipients
{
    /**
     * @param  list<string>  $permissions
     * @return Collection<int, User>
     */
    public function in(int $companyId, array $permissions, ?int $projectId = null): Collection
    {
        $tables = config('permission.table_names');
        $teamKey = config('permission.column_names.team_foreign_key');

        return User::query()
            ->where('is_active', true)
            ->whereHas('memberships', fn (Builder $m) => $m->where('company_id', $companyId)->where('is_active', true))
            ->whereExists(fn ($sub) => $sub->select(DB::raw(1))
                ->from($tables['model_has_roles'].' as mhr')
                ->join($tables['role_has_permissions'].' as rhp', 'rhp.role_id', '=', 'mhr.role_id')
                ->join($tables['permissions'].' as p', 'p.id', '=', 'rhp.permission_id')
                ->whereColumn('mhr.model_id', 'users.id')
                ->where('mhr.model_type', (new User)->getMorphClass())
                ->where('mhr.'.$teamKey, $companyId)
                ->whereIn('p.name', $permissions))
            ->when($projectId, fn (Builder $q, int $id) => $q->whereExists(fn ($sub) => $sub->select(DB::raw(1))
                ->from('project_users as pu')
                ->whereColumn('pu.user_id', 'users.id')
                ->where('pu.project_id', $id)
                ->where('pu.is_active', true)))
            ->get();
    }
}
