<?php

namespace App\Services\SiteExecution;

use App\Enums\Boq\BoqStatus;
use App\Enums\SiteExecution\SiteDiaryStatus;
use App\Models\Boq\BoqItem;
use App\Models\Projects\Project;
use App\Models\SiteExecution\SiteDiary;
use App\Models\SiteExecution\SiteDiaryEquipment;
use App\Models\SiteExecution\SiteDiaryLabour;
use App\Models\SiteExecution\SiteDiaryMaterial;
use App\Models\SiteExecution\SiteDiaryWorkItem;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Builds DPR content from the APPROVED site diaries of one project and date. Work lines are summed
 * per task + BOQ line + unit (free-text lines per description + unit), labour per trade +
 * subcontractor, equipment per type + description, material per item + unit. Weather and
 * issues are collected with the site name of each diary.
 */
class DprAggregationService
{
    /**
     * @return Collection<int, SiteDiary>
     */
    public function approvedDiaries(Project $project, string $date): Collection
    {
        return SiteDiary::query()
            ->where('project_id', $project->id)
            ->whereDate('diary_date', $date)
            ->where('status', SiteDiaryStatus::Approved)
            ->with(['site:id,name', 'workItems.task:id,name,wbs_code', 'workItems.boqItem:id,item_code,name,line_uid', 'labours', 'equipment', 'materials'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{diary_ids: list<int>, weather: ?string, site_issues: ?string, items: list<array<string, mixed>>, labours: list<array<string, mixed>>, equipment: list<array<string, mixed>>, materials: list<array<string, mixed>>}
     */
    public function aggregate(Project $project, string $date): array
    {
        $diaries = $this->approvedDiaries($project, $date);

        // A BOQ revision approved after the diary moves its lines to new ids; follow the line_uid.
        $currentLineIds = BoqItem::query()
            ->whereHas('boq', fn ($q) => $q->where('project_id', $project->id)->where('is_current', true)->where('status', BoqStatus::Approved))
            ->pluck('id', 'line_uid');

        $items = [];
        $labours = [];
        $equipment = [];
        $materials = [];
        $weather = [];
        $issues = [];

        foreach ($diaries as $diary) {
            $where = $diary->site?->name ?? $diary->work_location ?? 'Site';
            if (filled($diary->weather)) {
                $weather[mb_strtolower($diary->weather)] = $diary->weather;
            }
            foreach (['issues' => 'Issue', 'safety_incidents' => 'Safety'] as $field => $tag) {
                if (filled($diary->{$field})) {
                    $issues[] = "[{$where}] {$tag}: ".trim((string) $diary->{$field});
                }
            }

            $diary->workItems->each(function (SiteDiaryWorkItem $w) use (&$items, $currentLineIds) {
                $boqItemId = $w->boqItem ? ($currentLineIds[$w->boqItem->line_uid] ?? $w->boq_item_id) : $w->boq_item_id;
                $key = $w->task_id || $boqItemId
                    ? "t{$w->task_id}|b{$boqItemId}|u{$w->unit_id}"
                    : 'd'.mb_strtolower(trim((string) $w->description)).'|u'.$w->unit_id;
                $items[$key] ??= [
                    'task_id' => $w->task_id,
                    'boq_item_id' => $boqItemId,
                    'unit_id' => $w->unit_id,
                    'description' => $w->task?->name ?? ($w->boqItem ? trim($w->boqItem->item_code.' '.$w->boqItem->name) : $w->description),
                    'executed_qty' => '0',
                ];
                $items[$key]['executed_qty'] = Decimal::of($items[$key]['executed_qty'])->plus($w->quantity)->toQuantity();
            });

            $diary->labours->each(function (SiteDiaryLabour $l) use (&$labours) {
                $key = "{$l->labour_trade_id}|{$l->subcontractor_id}";
                $labours[$key] ??= ['labour_trade_id' => $l->labour_trade_id, 'subcontractor_id' => $l->subcontractor_id, 'headcount' => 0, 'hours' => '0'];
                $labours[$key]['headcount'] += (int) $l->headcount;
                $labours[$key]['hours'] = Decimal::of($labours[$key]['hours'])->plus($l->hours)->round(2)->toString();
            });

            $diary->equipment->each(function (SiteDiaryEquipment $e) use (&$equipment) {
                $key = "{$e->equipment_type_id}|".mb_strtolower(trim((string) $e->description));
                $equipment[$key] ??= ['equipment_type_id' => $e->equipment_type_id, 'description' => $e->description, 'working_hours' => '0', 'idle_hours' => '0'];
                $equipment[$key]['working_hours'] = Decimal::of($equipment[$key]['working_hours'])->plus($e->working_hours)->round(2)->toString();
                $equipment[$key]['idle_hours'] = Decimal::of($equipment[$key]['idle_hours'])->plus($e->idle_hours)->round(2)->toString();
            });

            $diary->materials->each(function (SiteDiaryMaterial $m) use (&$materials) {
                $key = "{$m->material_id}|{$m->unit_id}";
                $materials[$key] ??= ['material_id' => $m->material_id, 'unit_id' => $m->unit_id, 'quantity' => '0'];
                $materials[$key]['quantity'] = Decimal::of($materials[$key]['quantity'])->plus($m->quantity)->toQuantity();
            });
        }

        return [
            'diary_ids' => $diaries->pluck('id')->all(),
            'weather' => $weather === [] ? null : Str::limit(implode('; ', $weather), 150, ''),
            'site_issues' => $issues === [] ? null : implode("\n", $issues),
            'items' => array_values(array_map(fn (array $i) => ['description' => Str::limit((string) $i['description'], 500, '')] + $i, $items)),
            'labours' => array_values($labours),
            'equipment' => array_values($equipment),
            'materials' => array_values($materials),
        ];
    }
}
