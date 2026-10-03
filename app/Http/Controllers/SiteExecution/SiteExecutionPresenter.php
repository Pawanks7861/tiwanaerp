<?php

namespace App\Http\Controllers\SiteExecution;

use App\Enums\Boq\BoqStatus;
use App\Models\Boq\BoqItem;
use App\Models\Masters\EquipmentType;
use App\Models\Masters\LabourTrade;
use App\Models\Masters\Material;
use App\Models\Masters\Subcontractor;
use App\Models\Masters\Unit;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Models\Projects\Site;
use App\Models\User;
use App\Support\Math\Decimal;

/**
 * Form options for site diaries and DPRs. Task and BOQ options carry the context shown next to a
 * work line (WBS, planned / completed quantity, unit, BOQ quantity) but never BOQ rates or costs.
 */
final class SiteExecutionPresenter
{
    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public static function options(Project $project): array
    {
        return [
            'sites' => Site::query()->where('project_id', $project->id)->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Site $s) => ['value' => $s->id, 'label' => $s->name])->all(),
            'tasks' => self::taskOptions($project),
            'boq_items' => self::boqItemOptions($project),
            'units' => Unit::query()->active()->orderBy('symbol')->get(['id', 'name', 'symbol'])
                ->map(fn (Unit $u) => ['value' => $u->id, 'label' => $u->symbol, 'description' => $u->name])->all(),
            'subcontractors' => Subcontractor::query()->active()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Subcontractor $s) => ['value' => $s->id, 'label' => $s->name])->all(),
            'trades' => LabourTrade::query()->active()->orderBy('name')->get(['id', 'name'])
                ->map(fn (LabourTrade $t) => ['value' => $t->id, 'label' => $t->name])->all(),
            'equipment_types' => EquipmentType::query()->active()->orderBy('name')->get(['id', 'name'])
                ->map(fn (EquipmentType $t) => ['value' => $t->id, 'label' => $t->name])->all(),
            'materials' => Material::query()->active()->with('unit:id,symbol')->orderBy('name')->limit(2000)->get(['id', 'code', 'name', 'unit_id'])
                ->map(fn (Material $m) => [
                    'value' => $m->id,
                    'label' => $m->name,
                    'description' => $m->code.($m->unit ? ' · '.$m->unit->symbol : ''),
                    'unit_id' => $m->unit_id,
                    'unit' => $m->unit?->symbol,
                ])->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function taskOptions(Project $project): array
    {
        return ProjectTask::query()->where('project_id', $project->id)
            ->with(['unit:id,symbol', 'boqItem:id,item_code,name'])
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'wbs_code', 'name', 'unit_id', 'boq_item_id', 'planned_qty', 'completed_qty', 'status'])
            ->map(fn (ProjectTask $t) => [
                'value' => $t->id,
                'label' => trim($t->wbs_code.' '.$t->name),
                'description' => Decimal::of($t->planned_qty ?? '0')->isPositive()
                    ? sprintf('Planned %s · done %s %s', self::qty($t->planned_qty), self::qty($t->completed_qty), $t->unit?->symbol)
                    : $t->status->label(),
                'wbs_code' => $t->wbs_code,
                'name' => $t->name,
                'unit_id' => $t->unit_id,
                'unit' => $t->unit?->symbol,
                'planned_qty' => Decimal::of($t->planned_qty ?? '0')->isPositive() ? $t->planned_qty : null,
                'completed_qty' => $t->completed_qty,
                'boq_item_id' => $t->boq_item_id,
                'boq_item' => $t->boqItem ? trim($t->boqItem->item_code.' '.$t->boqItem->name) : null,
            ])->all();
    }

    /**
     * Lines of the current approved BOQ (no rates).
     *
     * @return list<array<string, mixed>>
     */
    public static function boqItemOptions(Project $project): array
    {
        return BoqItem::query()
            ->whereHas('boq', fn ($q) => $q->where('project_id', $project->id)->where('is_current', true)->where('status', BoqStatus::Approved))
            ->with('unit:id,symbol')
            ->orderBy('sort_order')
            ->get(['id', 'item_code', 'name', 'unit_id', 'quantity', 'line_uid'])
            ->map(fn (BoqItem $i) => [
                'value' => $i->id,
                'label' => trim(($i->item_code ? $i->item_code.' ' : '').$i->name),
                'description' => 'BOQ qty '.self::qty($i->quantity).' '.$i->unit?->symbol,
                'item_code' => $i->item_code,
                'unit_id' => $i->unit_id,
                'unit' => $i->unit?->symbol,
                'quantity' => $i->quantity,
            ])->all();
    }

    /**
     * The current-revision line for a BOQ item id saved before a BOQ revision (same line_uid).
     */
    public static function currentBoqItemId(Project $project, ?int $boqItemId): ?int
    {
        if ($boqItemId === null) {
            return null;
        }

        $uid = BoqItem::query()->whereKey($boqItemId)->value('line_uid');
        $current = $uid === null ? null : BoqItem::query()
            ->where('line_uid', $uid)
            ->whereHas('boq', fn ($q) => $q->where('project_id', $project->id)->where('is_current', true)->where('status', BoqStatus::Approved))
            ->value('id');

        return $current ?? $boqItemId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function memberOptions(Project $project): array
    {
        return $project->users()->wherePivot('is_active', true)->orderBy('name')->get(['users.id', 'users.name'])
            ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->all();
    }

    public static function qty(mixed $value): string
    {
        $string = Decimal::of($value === null ? '0' : (string) $value)->toQuantity();

        return str_contains($string, '.') ? rtrim(rtrim($string, '0'), '.') : $string;
    }
}
