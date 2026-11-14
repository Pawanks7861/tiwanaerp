<?php

namespace App\Services\Utilities;

use App\Enums\Utilities\ElectricityReadingStatus;
use App\Models\User;
use App\Models\Utilities\ElectricityConnection;
use App\Models\Utilities\ElectricityMeter;
use App\Models\Utilities\ElectricityMeterReading;
use App\Models\Utilities\ElectricityMeterTodReading;
use App\Support\Math\Decimal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ElectricityMeterReadingService
{
    public function __construct(private readonly ElectricityReadingCalculator $calculator) {}

    /**
     * Default opening kWh/kVAh from the previous confirmed closing for the same meter.
     *
     * @return array{opening_kwh: string, opening_kvah: ?string, multiplication_factor: string}
     */
    public function defaultOpenings(ElectricityMeter $meter): array
    {
        $prev = ElectricityMeterReading::query()
            ->where('electricity_meter_id', $meter->id)
            ->where('status', ElectricityReadingStatus::Confirmed)
            ->orderByDesc('billing_month')
            ->orderByDesc('revision')
            ->first();

        return [
            'opening_kwh' => $prev ? (string) $prev->closing_kwh : '0',
            'opening_kvah' => $prev && $prev->closing_kvah !== null ? (string) $prev->closing_kvah : null,
            'multiplication_factor' => (string) $meter->multiplication_factor,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{slot_code: string, slot_name: string, opening?: mixed, closing?: mixed, units?: mixed}>  $tod
     */
    public function create(array $data, array $tod = []): ElectricityMeterReading
    {
        return DB::transaction(function () use ($data, $tod) {
            [$meter, $connection] = $this->resolveMeterAndConnection($data);
            $this->assertHierarchy($data, $meter, $connection);
            $this->assertNoActiveDuplicate($meter, (string) $data['billing_month']);
            $data = $this->applyDefaultOpenings($data, $meter);

            $computed = $this->calculator->compute($this->readingPayload($data, $meter));

            $reading = new ElectricityMeterReading;
            $reading->fill([
                'utility_site_id' => $connection->utility_site_id,
                'electricity_connection_id' => $connection->id,
                'electricity_meter_id' => $meter->id,
                'billing_month' => $this->monthStart((string) $data['billing_month']),
                'from_date' => $data['from_date'],
                'to_date' => $data['to_date'],
                'opening_kwh' => $data['opening_kwh'],
                'closing_kwh' => $data['closing_kwh'],
                'opening_kvah' => $data['opening_kvah'] ?? null,
                'closing_kvah' => $data['closing_kvah'] ?? null,
                'mdi_reading' => $data['mdi_reading'] ?? null,
                'multiplication_factor' => $data['multiplication_factor'] ?? $meter->multiplication_factor,
                'remarks' => $data['remarks'] ?? null,
            ]);
            $reading->status = ElectricityReadingStatus::Draft;
            $reading->revision = 0;
            $reading->days = $computed['days'];
            $reading->kwh_consumption = $computed['kwh_consumption'];
            $reading->kvah_consumption = $computed['kvah_consumption'];
            $reading->billing_demand = $computed['billing_demand'];
            $reading->power_factor = $computed['power_factor'];
            $reading->save();

            $this->syncTod($reading, $tod);

            return $reading->fresh(['todReadings', 'meter', 'connection', 'site']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{slot_code: string, slot_name: string, opening?: mixed, closing?: mixed, units?: mixed}>|null  $tod
     */
    public function update(ElectricityMeterReading $reading, array $data, ?array $tod = null): ElectricityMeterReading
    {
        if (! $reading->isEditable()) {
            throw ValidationException::withMessages(['status' => 'Only draft readings can be edited.']);
        }

        return DB::transaction(function () use ($reading, $data, $tod) {
            $meter = $reading->meter ?? ElectricityMeter::query()->findOrFail($reading->electricity_meter_id);
            $payload = array_merge([
                'from_date' => $reading->from_date?->toDateString(),
                'to_date' => $reading->to_date?->toDateString(),
                'opening_kwh' => (string) $reading->opening_kwh,
                'closing_kwh' => (string) $reading->closing_kwh,
                'opening_kvah' => $reading->opening_kvah !== null ? (string) $reading->opening_kvah : null,
                'closing_kvah' => $reading->closing_kvah !== null ? (string) $reading->closing_kvah : null,
                'mdi_reading' => $reading->mdi_reading !== null ? (string) $reading->mdi_reading : null,
                'multiplication_factor' => (string) $reading->multiplication_factor,
            ], $data);
            $payload = $this->applyDefaultOpenings($payload, $meter);

            $computed = $this->calculator->compute($payload);

            $reading->fill([
                'from_date' => $payload['from_date'],
                'to_date' => $payload['to_date'],
                'opening_kwh' => $payload['opening_kwh'],
                'closing_kwh' => $payload['closing_kwh'],
                'opening_kvah' => $payload['opening_kvah'] ?? null,
                'closing_kvah' => $payload['closing_kvah'] ?? null,
                'mdi_reading' => $payload['mdi_reading'] ?? null,
                'multiplication_factor' => $payload['multiplication_factor'],
                'remarks' => $data['remarks'] ?? $reading->remarks,
            ]);
            $reading->days = $computed['days'];
            $reading->kwh_consumption = $computed['kwh_consumption'];
            $reading->kvah_consumption = $computed['kvah_consumption'];
            $reading->billing_demand = $computed['billing_demand'];
            $reading->power_factor = $computed['power_factor'];
            $reading->save();

            if ($tod !== null) {
                $this->syncTod($reading, $tod);
            }

            return $reading->fresh(['todReadings', 'meter', 'connection', 'site']);
        });
    }

    public function confirm(ElectricityMeterReading $reading, User $user): ElectricityMeterReading
    {
        if ($reading->status !== ElectricityReadingStatus::Draft) {
            throw ValidationException::withMessages(['status' => 'Only draft readings can be confirmed.']);
        }

        $reading->status = ElectricityReadingStatus::Confirmed;
        $reading->confirmed_by = $user->id;
        $reading->confirmed_at = now();
        $reading->save();

        return $reading->fresh();
    }

    public function cancel(ElectricityMeterReading $reading): ElectricityMeterReading
    {
        if ($reading->status === ElectricityReadingStatus::Cancelled) {
            return $reading;
        }
        if ($reading->bill()->exists()) {
            throw ValidationException::withMessages(['status' => 'Cancel the linked bill before cancelling this reading.']);
        }

        $reading->status = ElectricityReadingStatus::Cancelled;
        $reading->save();

        return $reading->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ElectricityMeter, 1: ElectricityConnection}
     */
    private function resolveMeterAndConnection(array $data): array
    {
        $meter = ElectricityMeter::query()->findOrFail($data['electricity_meter_id']);
        $connection = ElectricityConnection::query()->findOrFail($data['electricity_connection_id'] ?? $meter->electricity_connection_id);
        if ((int) $meter->electricity_connection_id !== (int) $connection->id) {
            throw ValidationException::withMessages(['electricity_meter_id' => 'Meter does not belong to the selected connection.']);
        }

        return [$meter, $connection];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertHierarchy(array $data, ElectricityMeter $meter, ElectricityConnection $connection): void
    {
        if (isset($data['utility_site_id']) && (int) $data['utility_site_id'] !== (int) $connection->utility_site_id) {
            throw ValidationException::withMessages(['utility_site_id' => 'Site does not match the connection.']);
        }
        if (! $meter->is_active) {
            throw ValidationException::withMessages(['electricity_meter_id' => 'Meter is inactive.']);
        }
    }

    private function assertNoActiveDuplicate(ElectricityMeter $meter, string $billingMonth): void
    {
        $month = $this->monthStart($billingMonth);
        $exists = ElectricityMeterReading::query()
            ->where('electricity_meter_id', $meter->id)
            ->whereDate('billing_month', $month)
            ->where('status', '!=', ElectricityReadingStatus::Cancelled)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'billing_month' => 'A reading already exists for this meter and month. Use a correction revision workflow instead of overwriting.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyDefaultOpenings(array $data, ElectricityMeter $meter): array
    {
        $defaults = $this->defaultOpenings($meter);

        if (! array_key_exists('opening_kwh', $data) || $data['opening_kwh'] === null || $data['opening_kwh'] === '') {
            $data['opening_kwh'] = $defaults['opening_kwh'];
        }

        if (! array_key_exists('opening_kvah', $data) || $data['opening_kvah'] === null || $data['opening_kvah'] === '') {
            $data['opening_kvah'] = $defaults['opening_kvah'];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function readingPayload(array $data, ElectricityMeter $meter): array
    {
        return [
            'from_date' => $data['from_date'],
            'to_date' => $data['to_date'],
            'opening_kwh' => $data['opening_kwh'],
            'closing_kwh' => $data['closing_kwh'],
            'opening_kvah' => $data['opening_kvah'] ?? null,
            'closing_kvah' => $data['closing_kvah'] ?? null,
            'mdi_reading' => $data['mdi_reading'] ?? null,
            'multiplication_factor' => $data['multiplication_factor'] ?? $meter->multiplication_factor,
        ];
    }

    /**
     * @param  list<array{slot_code: string, slot_name: string, opening?: mixed, closing?: mixed, units?: mixed}>  $tod
     */
    private function syncTod(ElectricityMeterReading $reading, array $tod): void
    {
        ElectricityMeterTodReading::query()->where('electricity_meter_reading_id', $reading->id)->delete();

        foreach ($tod as $slot) {
            $units = $slot['units'] ?? null;
            if (($units === null || $units === '') && isset($slot['opening'], $slot['closing'])) {
                $units = Decimal::of($slot['closing'])->minus($slot['opening'])->toQuantity();
            }
            ElectricityMeterTodReading::query()->create([
                'electricity_meter_reading_id' => $reading->id,
                'slot_code' => $slot['slot_code'],
                'slot_name' => $slot['slot_name'],
                'opening' => $slot['opening'] ?? null,
                'closing' => $slot['closing'] ?? null,
                'units' => $units ?? '0',
            ]);
        }
    }

    private function monthStart(string $billingMonth): string
    {
        return Carbon::parse($billingMonth)->startOfMonth()->toDateString();
    }
}
