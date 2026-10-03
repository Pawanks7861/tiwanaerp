<?php

namespace App\Support\Inventory;

use App\Models\Masters\Warehouse;
use App\Models\Projects\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Warehouses reachable from a project: the company's central stores (project_id null, shared by
 * every project) plus the project's own site stores. Other projects' site stores are never
 * reachable from this project, for reading or posting.
 */
class InventoryScope
{
    /**
     * @return Builder<Warehouse>
     */
    public function warehouses(Project $project): Builder
    {
        return Warehouse::query()->where(fn (Builder $q) => $q->whereNull('project_id')->orWhere('project_id', $project->id));
    }

    /**
     * @return list<int>
     */
    public function warehouseIds(Project $project): array
    {
        return $this->warehouses($project)->withTrashed()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function contains(Project $project, int $warehouseId): bool
    {
        return in_array($warehouseId, $this->warehouseIds($project), true);
    }

    /**
     * Resolve a warehouse chosen for a document in this project, or fail validation on $key.
     */
    public function warehouse(Project $project, mixed $id, string $key = 'warehouse_id'): Warehouse
    {
        $warehouse = is_numeric($id)
            ? $this->warehouses($project)->where('is_active', true)->whereKey((int) $id)->first()
            : null;

        return $warehouse ?? throw ValidationException::withMessages([$key => 'Choose an active central store or a store of this project.']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function options(Project $project): array
    {
        return $this->warehouses($project)->where('is_active', true)->orderByRaw('project_id is null')->orderBy('name')
            ->get(['id', 'code', 'name', 'project_id'])
            ->map(fn (Warehouse $w) => [
                'value' => $w->id,
                'label' => $w->name,
                'description' => $w->code.($w->project_id === null ? ' · Central store' : ' · Site store'),
            ])->all();
    }
}
