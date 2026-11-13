<?php

namespace App\Services\Utilities;

use App\Enums\Utilities\ElectricityBillStatus;
use App\Enums\Utilities\ElectricityReadingStatus;
use App\Models\User;
use App\Models\Utilities\ElectricityBill;
use App\Models\Utilities\ElectricityBillComponent;
use App\Models\Utilities\ElectricityMeterReading;
use App\Services\Numbering\DocumentNumberService;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ElectricityBillService
{
    public function __construct(
        private readonly ElectricityBillCalculationService $calculator,
        private readonly DocumentNumberService $numbers,
    ) {}

    /**
     * Create a bill from a confirmed reading and persist calculated component snapshot.
     *
     * @param  array<string, mixed>  $data
     */
    public function createFromReading(ElectricityMeterReading $reading, array $data = []): ElectricityBill
    {
        if ($reading->status !== ElectricityReadingStatus::Confirmed) {
            throw ValidationException::withMessages(['reading' => 'Confirm the meter reading before creating a bill.']);
        }
        if ($reading->bill()->exists()) {
            throw ValidationException::withMessages(['reading' => 'A bill already exists for this reading.']);
        }

        return DB::transaction(function () use ($reading, $data) {
            $result = $this->calculator->calculate($reading);

            $bill = new ElectricityBill;
            $bill->fill([
                'utility_site_id' => $reading->utility_site_id,
                'electricity_connection_id' => $reading->electricity_connection_id,
                'electricity_meter_reading_id' => $reading->id,
                'electricity_tariff_id' => $result['tariff']->id,
                'bill_date' => $data['bill_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'remarks' => $data['remarks'] ?? null,
            ]);
            $bill->bill_number = $data['bill_number'] ?? $this->numbers->next('electricity_bill');
            $bill->billing_month = $reading->billing_month;
            $bill->status = ElectricityBillStatus::Calculated;
            $bill->calculated_total = $result['calculated_total'];
            $bill->cost_per_kwh = $result['cost_per_kwh'];
            $bill->cost_per_kvah = $result['cost_per_kvah'];
            $bill->demand_utilization_percent = $result['demand_utilization_percent'];
            $bill->excess_demand = $result['excess_demand'];
            $bill->tariff_snapshot = $result['tariff_snapshot'];
            $bill->reading_snapshot = $result['reading_snapshot'];
            $bill->save();

            $this->replaceComponents($bill, $result['components']);

            return $bill->fresh(['components', 'reading', 'tariff', 'site', 'connection']);
        });
    }

    public function recalculate(ElectricityBill $bill): ElectricityBill
    {
        if ($bill->status?->isImmutable()) {
            throw ValidationException::withMessages(['status' => 'Approved bills cannot be recalculated.']);
        }
        if ($bill->status === ElectricityBillStatus::Cancelled) {
            throw ValidationException::withMessages(['status' => 'Cancelled bills cannot be recalculated.']);
        }

        return DB::transaction(function () use ($bill) {
            $reading = $bill->reading ?? ElectricityMeterReading::query()->findOrFail($bill->electricity_meter_reading_id);
            $result = $this->calculator->calculate($reading);

            $actualByCode = $bill->components->keyBy('component_code');

            $bill->electricity_tariff_id = $result['tariff']->id;
            $bill->calculated_total = $result['calculated_total'];
            $bill->cost_per_kwh = $result['cost_per_kwh'];
            $bill->cost_per_kvah = $result['cost_per_kvah'];
            $bill->demand_utilization_percent = $result['demand_utilization_percent'];
            $bill->excess_demand = $result['excess_demand'];
            $bill->tariff_snapshot = $result['tariff_snapshot'];
            $bill->reading_snapshot = $result['reading_snapshot'];
            if ($bill->status === ElectricityBillStatus::Draft) {
                $bill->status = ElectricityBillStatus::Calculated;
            }
            $bill->save();

            $this->replaceComponents($bill, $result['components'], $actualByCode);

            if ($bill->actual_bill_total !== null) {
                $this->refreshVariance($bill->fresh('components'));
            }

            return $bill->fresh(['components', 'reading', 'tariff', 'site', 'connection']);
        });
    }

    /**
     * Enter actual DISCOM bill amounts (component-level + total).
     *
     * @param  array<string, mixed>  $data
     * @param  list<array{component_code: string, actual_amount: mixed, remarks?: string|null}>  $actualComponents
     */
    public function enterActual(ElectricityBill $bill, array $data, array $actualComponents = []): ElectricityBill
    {
        if (! $bill->isEditable()) {
            throw ValidationException::withMessages(['status' => 'This bill is not editable.']);
        }

        return DB::transaction(function () use ($bill, $data, $actualComponents) {
            $bill->fill([
                'bill_date' => $data['bill_date'] ?? $bill->bill_date,
                'due_date' => $data['due_date'] ?? $bill->due_date,
                'remarks' => $data['remarks'] ?? $bill->remarks,
            ]);
            if (! empty($data['bill_number'])) {
                $bill->bill_number = $data['bill_number'];
            }

            foreach ($actualComponents as $row) {
                $component = $bill->components()->where('component_code', $row['component_code'])->first();
                if (! $component) {
                    continue;
                }
                $actual = Decimal::of($row['actual_amount'] ?? '0')->toMoney();
                $component->actual_amount = $actual;
                $component->variance_amount = Decimal::of($actual)->minus($component->calculated_amount)->toMoney();
                if (array_key_exists('remarks', $row)) {
                    $component->remarks = $row['remarks'];
                }
                $component->save();
            }

            $actualTotal = array_key_exists('actual_bill_total', $data) && $data['actual_bill_total'] !== null && $data['actual_bill_total'] !== ''
                ? Decimal::of($data['actual_bill_total'])->toMoney()
                : Decimal::sum($bill->components()->pluck('actual_amount')->filter(fn ($v) => $v !== null))->toMoney();

            $bill->actual_bill_total = $actualTotal;
            $bill->variance_total = Decimal::of($actualTotal)->minus($bill->calculated_total)->toMoney();
            $bill->status = ElectricityBillStatus::ActualEntered;
            $bill->save();

            return $bill->fresh(['components', 'reading', 'tariff', 'site', 'connection']);
        });
    }

    public function markReviewed(ElectricityBill $bill, User $user): ElectricityBill
    {
        if (! in_array($bill->status, [ElectricityBillStatus::ActualEntered, ElectricityBillStatus::Calculated], true)) {
            throw ValidationException::withMessages(['status' => 'Enter actual bill amounts before review, or review a calculated bill.']);
        }

        $bill->status = ElectricityBillStatus::Reviewed;
        $bill->reviewed_by = $user->id;
        $bill->reviewed_at = now();
        $bill->save();

        return $bill->fresh();
    }

    public function approve(ElectricityBill $bill, User $user): ElectricityBill
    {
        if ($bill->status !== ElectricityBillStatus::Reviewed) {
            throw ValidationException::withMessages(['status' => 'Only reviewed bills can be approved.']);
        }

        $bill->status = ElectricityBillStatus::Approved;
        $bill->approved_by = $user->id;
        $bill->approved_at = now();
        // Freeze snapshots already stored at calculation time.
        $bill->save();

        return $bill->fresh(['components']);
    }

    public function cancel(ElectricityBill $bill): ElectricityBill
    {
        if ($bill->status === ElectricityBillStatus::Approved) {
            throw ValidationException::withMessages(['status' => 'Approved bills cannot be cancelled without a reopen workflow.']);
        }

        $bill->status = ElectricityBillStatus::Cancelled;
        $bill->save();

        return $bill->fresh();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  \Illuminate\Support\Collection<string, ElectricityBillComponent>|null  $priorActuals
     */
    private function replaceComponents(ElectricityBill $bill, array $lines, $priorActuals = null): void
    {
        ElectricityBillComponent::query()->where('electricity_bill_id', $bill->id)->delete();

        foreach ($lines as $line) {
            $actual = null;
            $variance = null;
            if ($priorActuals !== null && $priorActuals->has($line['component_code'])) {
                $prior = $priorActuals->get($line['component_code']);
                if ($prior->actual_amount !== null) {
                    $actual = (string) $prior->actual_amount;
                    $variance = Decimal::of($actual)->minus($line['calculated_amount'])->toMoney();
                }
            }

            ElectricityBillComponent::query()->create([
                'electricity_bill_id' => $bill->id,
                'tariff_component_id' => $line['tariff_component_id'] ?? null,
                'component_code' => $line['component_code'],
                'component_name' => $line['component_name'],
                'calculation_basis' => $line['calculation_basis'],
                'quantity' => $line['quantity'] ?? null,
                'rate' => $line['rate'] ?? null,
                'percentage' => $line['percentage'] ?? null,
                'calculated_amount' => $line['calculated_amount'],
                'actual_amount' => $actual,
                'variance_amount' => $variance,
                'is_rebate' => (bool) ($line['is_rebate'] ?? false),
                'is_tax' => (bool) ($line['is_tax'] ?? false),
                'is_penalty' => (bool) ($line['is_penalty'] ?? false),
                'sort_order' => $line['sort_order'] ?? 0,
            ]);
        }
    }

    private function refreshVariance(ElectricityBill $bill): void
    {
        foreach ($bill->components as $component) {
            if ($component->actual_amount === null) {
                continue;
            }
            $component->variance_amount = Decimal::of($component->actual_amount)->minus($component->calculated_amount)->toMoney();
            $component->save();
        }

        if ($bill->actual_bill_total !== null) {
            $bill->variance_total = Decimal::of($bill->actual_bill_total)->minus($bill->calculated_total)->toMoney();
            $bill->save();
        }
    }
}
