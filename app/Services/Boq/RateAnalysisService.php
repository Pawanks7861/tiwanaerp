<?php

namespace App\Services\Boq;

use App\Enums\Boq\RateAnalysisStatus;
use App\Enums\Boq\ResourceType;
use App\Models\Boq\RateAnalysis;
use App\Models\Boq\RateAnalysisItem;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Numbering\DocumentNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RateAnalysisService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    /**
     * Create or update a draft analysis together with its resource lines (lines are replaced).
     *
     * @param  array<string, mixed>  $data  validated header + items
     */
    public function save(Project $project, array $data, ?RateAnalysis $analysis = null): RateAnalysis
    {
        return DB::transaction(function () use ($project, $data, $analysis) {
            if ($analysis !== null) {
                RateAnalysis::query()->whereKey($analysis->id)->lockForUpdate()->firstOrFail()->assertEditable();
            }

            $items = array_values($data['items'] ?? []);
            $calc = RateAnalysisCalculator::calculate([
                'output_quantity' => (string) $data['output_quantity'],
                'overhead_percent' => (string) ($data['overhead_percent'] ?? '0'),
                'profit_percent' => (string) ($data['profit_percent'] ?? '0'),
            ], array_map(fn (array $item) => [
                'resource_type' => $item['resource_type'],
                'quantity' => (string) $item['quantity'],
                'wastage_percent' => (string) ($item['wastage_percent'] ?? '0'),
                'rate' => (string) $item['rate'],
            ], $items));

            $analysis ??= new RateAnalysis;
            $analysis->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'unit_id' => $data['unit_id'],
                'output_quantity' => (string) $data['output_quantity'],
                'overhead_percent' => (string) ($data['overhead_percent'] ?? '0'),
                'profit_percent' => (string) ($data['profit_percent'] ?? '0'),
            ]);
            if (! $analysis->exists) {
                $analysis->forceFill([
                    'project_id' => $project->id,
                    'code' => $this->numbers->next('rate_analysis'),
                    'status' => RateAnalysisStatus::Draft,
                ]);
            }
            $analysis->forceFill(array_intersect_key($calc, array_flip([...RateAnalysis::COST_FIELDS, 'unit_rate'])))->save();

            $analysis->items()->get()->each->delete();
            foreach ($items as $index => $item) {
                $type = ResourceType::from($item['resource_type']);
                $line = new RateAnalysisItem([
                    'resource_type' => $type,
                    'material_id' => $type === ResourceType::Material ? ($item['material_id'] ?? null) : null,
                    'labour_trade_id' => $type === ResourceType::Labour ? ($item['labour_trade_id'] ?? null) : null,
                    'equipment_type_id' => $type === ResourceType::Equipment ? ($item['equipment_type_id'] ?? null) : null,
                    'description' => $item['description'],
                    'unit_id' => $item['unit_id'] ?? null,
                    'quantity' => (string) $item['quantity'],
                    'wastage_percent' => (string) ($item['wastage_percent'] ?? '0'),
                    'rate' => (string) $item['rate'],
                    'amount' => $calc['items'][$index]['amount'],
                    'sort_order' => $index + 1,
                ]);
                $line->rateAnalysis()->associate($analysis);
                $line->save();
            }

            return $analysis;
        });
    }

    public function approve(RateAnalysis $analysis, User $user): RateAnalysis
    {
        return DB::transaction(function () use ($analysis, $user) {
            $locked = RateAnalysis::query()->whereKey($analysis->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();

            if (! $locked->items()->exists()) {
                throw ValidationException::withMessages(['rate_analysis' => 'Add at least one resource line before approving.']);
            }

            $locked->forceFill([
                'status' => RateAnalysisStatus::Approved,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ])->save();

            return $locked;
        });
    }

    public function delete(RateAnalysis $analysis): void
    {
        $analysis->assertEditable();
        $analysis->delete();
    }
}
