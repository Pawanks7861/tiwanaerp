<?php

namespace App\Services\Equipment;

use App\Models\Equipment\Equipment;
use App\Models\Equipment\EquipmentAssignment;
use App\Models\Equipment\EquipmentFuelLog;
use App\Models\Inventory\MaterialIssue;
use App\Models\Masters\Material;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Inventory\MaterialIssueService;
use App\Services\Resources\Concerns\ResolvesProjectRefs;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fuel added to a machine is issued from inventory. The stock movement and its project cost
 * are posted by MaterialIssueService once. This log stores the issue reference and does not
 * post a second cost. Tank opening and consumption stay on the log.
 */
class EquipmentFuelService
{
    use ResolvesProjectRefs;

    public function __construct(private readonly MaterialIssueService $issues) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, array $data, User $user): EquipmentFuelLog
    {
        return DB::transaction(function () use ($project, $data, $user) {
            $values = $this->values($project, $data);
            $added = Decimal::of($values['fuel_added']);
            $log = new EquipmentFuelLog;
            $log->forceFill([...$values, 'project_id' => $project->id])->save();

            if ($added->isPositive()) {
                $this->issue($log, $project, $data, $user);
            }

            return $log->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(EquipmentFuelLog $log, array $data): void
    {
        DB::transaction(function () use ($log, $data) {
            $locked = EquipmentFuelLog::query()->whereKey($log->id)->lockForUpdate()->firstOrFail();
            $project = Project::query()->findOrFail($locked->project_id);
            $values = $this->values($project, $data);

            if ($locked->material_issue_id !== null) {
                $changed = Decimal::of($values['fuel_added'])->toMoney() !== Decimal::of($locked->fuel_added)->toMoney()
                    || (int) ($data['material_id'] ?? 0) !== (int) $locked->material_id
                    || (int) ($data['warehouse_id'] ?? 0) !== (int) $locked->warehouse_id;
                if ($changed) {
                    throw ValidationException::withMessages(['fuel_added' => 'Fuel already issued from inventory cannot be changed. Delete the log to reverse the issue.']);
                }
                $values['fuel_rate'] = $locked->fuel_rate;
                $values['cost'] = $locked->cost;
                $values['material_id'] = $locked->material_id;
                $values['unit_id'] = $locked->unit_id;
                $values['warehouse_id'] = $locked->warehouse_id;
            } elseif (Decimal::of($values['fuel_added'])->isPositive()) {
                throw ValidationException::withMessages(['fuel_added' => 'Record added fuel as a new log so it can be issued from inventory.']);
            }

            $locked->forceFill($values)->save();
        });
    }

    public function delete(EquipmentFuelLog $log, User $user): void
    {
        DB::transaction(function () use ($log, $user) {
            $locked = EquipmentFuelLog::query()->whereKey($log->id)->lockForUpdate()->firstOrFail();
            if ($locked->material_issue_id !== null) {
                $issue = MaterialIssue::query()->find($locked->material_issue_id);
                if ($issue !== null) {
                    $this->issues->cancel($issue, $user, 'Equipment fuel log deleted');
                }
            }
            $locked->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function issue(EquipmentFuelLog $log, Project $project, array $data, User $user): void
    {
        $material = Material::query()->whereKey((int) ($data['material_id'] ?? 0))->first()
            ?? throw ValidationException::withMessages(['material_id' => 'Choose the fuel material from inventory.']);
        if (blank($data['warehouse_id'] ?? null)) {
            throw ValidationException::withMessages(['warehouse_id' => 'Choose the store the fuel is issued from.']);
        }

        $equipment = Equipment::query()->findOrFail($log->equipment_id);
        $item = $this->issues->issueForEquipment($project, [
            'material_id' => $material->id,
            'quantity' => $log->fuel_added,
            'issue_date' => $log->log_date->toDateString(),
            'issued_to_name' => $equipment->name,
            'warehouse_id' => (int) $data['warehouse_id'],
            'remarks' => $data['remarks'] ?? null,
        ], $user->id);

        $log->forceFill([
            'material_id' => $material->id,
            'unit_id' => $item->unit_id,
            'warehouse_id' => (int) $data['warehouse_id'],
            'material_issue_id' => $item->material_issue_id,
            'material_issue_item_id' => $item->id,
            'fuel_rate' => $item->unit_cost,
            'cost' => $item->amount,
        ])->save();
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
        $closing = $opening->plus($added)->minus($consumed);
        if ($closing->isNegative()) {
            throw ValidationException::withMessages(['fuel_consumed' => 'Consumption cannot exceed the opening fuel plus fuel added.']);
        }
        if ($added->isPositive() && blank($data['material_id'] ?? null)) {
            throw ValidationException::withMessages(['material_id' => 'Choose the fuel material. Added fuel is issued from inventory.']);
        }

        return [
            'equipment_id' => $equipment->id,
            'log_date' => $date,
            'opening_fuel' => $opening->toMoney(),
            'fuel_added' => $added->toMoney(),
            'fuel_consumed' => $consumed->toMoney(),
            'closing_fuel' => $closing->toMoney(),
            'fuel_rate' => '0.0000',
            'cost' => '0.00',
            'site_id' => $data['site_id'] ?? null,
            'operator_name' => $data['operator_name'] ?? null,
            'meter_reading' => blank($data['meter_reading'] ?? null) ? null : $this->amount($data['meter_reading'], 'meter_reading', 2)->toMoney(),
            'remarks' => $data['remarks'] ?? null,
        ];
    }
}
