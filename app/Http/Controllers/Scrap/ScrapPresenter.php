<?php

namespace App\Http\Controllers\Scrap;

use App\Models\Masters\Department;
use App\Models\Masters\ScrapCategory;
use App\Models\Masters\ScrapType;
use App\Models\Projects\Project;
use App\Models\Scrap\ScrapBalance;
use App\Services\Scrap\ScrapBalanceService;

final class ScrapPresenter
{
    /**
     * @return list<array{value: int, label: string}>
     */
    public static function categoryOptions(): array
    {
        return ScrapCategory::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn (ScrapCategory $c) => [
                'value' => $c->id,
                'label' => $c->name,
                'description' => $c->code,
            ])
            ->all();
    }

    /**
     * @return list<array{value: int, label: string, description: string|null, scrap_category_id: int, unit_id: int|null, unit: string|null}>
     */
    public static function typeOptions(): array
    {
        return ScrapType::query()
            ->active()
            ->with('unit:id,symbol')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'scrap_category_id', 'code', 'name', 'unit_id'])
            ->map(fn (ScrapType $t) => [
                'value' => $t->id,
                'label' => $t->name,
                'description' => $t->code,
                'scrap_category_id' => $t->scrap_category_id,
                'unit_id' => $t->unit_id,
                'unit' => $t->unit?->symbol,
            ])
            ->all();
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function departmentOptions(): array
    {
        return Department::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $d) => ['value' => $d->id, 'label' => $d->name])
            ->all();
    }

    /**
     * @return array<string, string> keys scrap_type_id|unit_id → available qty
     */
    public static function balanceMap(Project $project): array
    {
        $map = [];
        foreach (app(ScrapBalanceService::class)->summary($project->id) as $row) {
            $map["{$row['scrap_type_id']}|{$row['unit_id']}"] = $row['quantity'];
        }

        return $map;
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function projectFilterOptions(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        return \App\Models\Projects\Project::query()
            ->whereIn('id', $projectIds)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn ($p) => ['value' => $p->id, 'label' => trim($p->code.' — '.$p->name)])
            ->all();
    }

    public static function balanceRow(ScrapBalance $balance): array
    {
        $balance->loadMissing(['project:id,code,name', 'scrapType:id,name,code,scrap_category_id', 'scrapType.category:id,name', 'unit:id,symbol']);

        return [
            'id' => $balance->id,
            'project_id' => $balance->project_id,
            'project' => $balance->project?->only(['id', 'code', 'name']),
            'scrap_type_id' => $balance->scrap_type_id,
            'scrap_type' => $balance->scrapType?->only(['id', 'name', 'code']),
            'category' => $balance->scrapType?->category?->name,
            'unit_id' => $balance->unit_id,
            'unit' => $balance->unit?->symbol,
            'quantity' => $balance->quantity,
            'qty_generated' => $balance->qty_generated,
            'qty_dispatched' => $balance->qty_dispatched,
            'qty_adjusted_net' => $balance->qty_adjusted_net,
            'last_movement_at' => $balance->last_movement_at?->toIso8601String(),
        ];
    }
}
