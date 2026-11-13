<?php

namespace App\Services\Utilities;

use App\Models\Utilities\ElectricityConnection;
use App\Models\Utilities\ElectricityTariff;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

final class ElectricityTariffResolver
{
    public function forPeriod(ElectricityConnection $connection, CarbonInterface $onDate): ElectricityTariff
    {
        $date = $onDate->toDateString();

        $matches = ElectricityTariff::query()
            ->where('electricity_connection_id', $connection->id)
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->with('components')
            ->orderByDesc('effective_from')
            ->get();

        if ($matches->isEmpty()) {
            throw ValidationException::withMessages([
                'electricity_tariff_id' => 'No active tariff covers '.$date.' for this connection.',
            ]);
        }

        if ($matches->count() > 1) {
            $overlapping = $matches->take(2)->pluck('name')->implode(', ');
            throw ValidationException::withMessages([
                'electricity_tariff_id' => "Overlapping tariffs for {$date}: {$overlapping}. Resolve in tariff admin.",
            ]);
        }

        return $matches->first();
    }
}
