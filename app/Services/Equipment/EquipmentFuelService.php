<?php

namespace App\Services\Equipment;

use App\Models\Equipment\Equipment;
use App\Models\Equipment\EquipmentAssignment;
use App\Models\Equipment\EquipmentFuelLog;
use App\Models\Projects\Project;
use App\Services\Resources\Concerns\ResolvesProjectRefs;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fuel logs: closing = opening + added − consumed (≥ 0); cost = added × rate (2 dp). The cost is
 * a record only and is never posted to the project cost ledger (the fuel's cost arrives through
 * its purchase: a diesel material issue or a Phase 7 expense), so it cannot be counted twice.
 */
class EquipmentFuelService
{
    use ResolvesProjectRefs;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, array $data): EquipmentFuelLog
    {
        return DB::transaction(function () use ($project, $data) {
            $log = new EquipmentFuelLog;
            $log->forceFill([...$this->values($project, $data), 'project_id' => $project->id])->save();

            return $log;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(EquipmentFuelLog $log, array $data): void
    {
        DB::transaction(function () use ($log, $data) {
            $locked = EquipmentFuelLog::query()->whereKey($log->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill($this->values(Project::query()->findOrFail($locked->project_id), $data))->save();
        });
    }

    public function delete(EquipmentFuelLog $log): void
    {
        $log->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function values(Project $project, array $data): array
    {
        $equipment = Equipment::query()->whereKey((int) ($data['equipment_id'] ?? 0))->first()
            ?? throw ValidationException::withMessages(['equipment_id' => 'Choose equipment of this company.']);

        $date = (string) $data['log_date'];
        $deployed = EquipmentAssignment::query()->where('project_id', $project->id)->where('equipment_id', $equipment->id)
            ->whereDate('issue_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('return_date')->orWhereDate('return_date', '>=', $date))
            ->exists();
        if (! $deployed) {
            throw ValidationException::withMessages(['equipment_id' => "{$equipment->name} was not assigned to this project on {$date}."]);
        }

        $opening = $this->amount($data['opening_fuel'] ?? null, 'opening_fuel', 2);
        $added = $this->amount($data['fuel_added'] ?? null, 'fuel_added', 2);
        $consumed = $this->amount($data['fuel_consumed'] ?? null, 'fuel_consumed', 2);
        $rate = $this->amount($data['fuel_rate'] ?? null, 'fuel_rate', Decimal::RATE_SCALE);
        $closing = $opening->plus($added)->minus($consumed);
        if ($closing->isNegative()) {
            throw ValidationException::withMessages(['fuel_consumed' => 'Consumption cannot exceed the opening fuel plus fuel added.']);
        }

        return [
            'equipment_id' => $equipment->id,
            'log_date' => $date,
            'opening_fuel' => $opening->toMoney(),
            'fuel_added' => $added->toMoney(),
            'fuel_consumed' => $consumed->toMoney(),
            'closing_fuel' => $closing->toMoney(),
            'fuel_rate' => $rate->toRate(),
            'cost' => $added->times($rate)->round(2)->toMoney(),
            'remarks' => $data['remarks'] ?? null,
        ];
    }
}
