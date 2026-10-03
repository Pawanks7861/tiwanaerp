<?php

namespace App\Models\Core;

use App\Models\Concerns\Auditable;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Company-scoped role (Spatie teams mode: team_id = company_id).
 * Spatie does not scope role queries by team, so listings and route binding do it here.
 */
class Role extends SpatieRole
{
    use Auditable;

    protected $fillable = ['name', 'guard_name', 'description', 'is_system', 'team_id'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForCurrentCompany(Builder $query): void
    {
        $query->where('team_id', app(CurrentCompany::class)->id() ?? 0);
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return static::query()->forCurrentCompany()->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    /**
     * Replaces the role's permissions with the given names in one query. Spatie's syncPermissions()
     * resolves and guard-checks each name individually, which is slow for catalogue-sized lists.
     *
     * @param  list<string>  $names
     */
    public function syncPermissionNames(array $names): void
    {
        $registrar = app(PermissionRegistrar::class);
        $ids = $registrar->getPermissionClass()::query()
            ->where('guard_name', $this->guard_name)
            ->whereIn('name', $names)
            ->pluck('id')
            ->all();

        $relation = $this->permissions();
        $pivot = $relation->newPivotStatement();
        $roleKey = $relation->getForeignPivotKeyName();
        $permissionKey = $relation->getRelatedPivotKeyName();

        $pivot->clone()->where($roleKey, $this->getKey())->delete();
        foreach (array_chunk($ids, 500) as $chunk) {
            $pivot->clone()->insert(array_map(fn ($id) => [$roleKey => $this->getKey(), $permissionKey => $id], $chunk));
        }

        $this->unsetRelation('permissions');
        $registrar->forgetCachedPermissions();
    }
}
