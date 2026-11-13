<?php

namespace App\Services\Utilities;

use App\Enums\Utilities\ElectricityCalculationBasis;
use App\Enums\Utilities\ElectricityComponentType;
use App\Models\Utilities\ElectricityConnection;
use App\Models\Utilities\ElectricityTariff;
use App\Models\Utilities\ElectricityTariffComponent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ElectricityTariffService
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $components
     */
    public function create(ElectricityConnection $connection, array $data, array $components = []): ElectricityTariff
    {
        return DB::transaction(function () use ($connection, $data, $components) {
            $this->assertNoOverlap($connection->id, (string) $data['effective_from'], $data['effective_to'] ?? null);

            $tariff = new ElectricityTariff;
            $tariff->fill([
                'electricity_connection_id' => $connection->id,
                'name' => $data['name'],
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null,
                'tariff_category' => $data['tariff_category'] ?? $connection->tariff_category,
                'state_code' => $data['state_code'] ?? $connection->state_code,
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);
            $tariff->save();

            $this->syncComponents($tariff, $components);

            return $tariff->fresh('components');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>|null  $components
     */
    public function update(ElectricityTariff $tariff, array $data, ?array $components = null): ElectricityTariff
    {
        return DB::transaction(function () use ($tariff, $data, $components) {
            $from = (string) ($data['effective_from'] ?? $tariff->effective_from?->toDateString());
            $to = array_key_exists('effective_to', $data) ? $data['effective_to'] : $tariff->effective_to?->toDateString();
            $this->assertNoOverlap((int) $tariff->electricity_connection_id, $from, $to, $tariff->id);

            $tariff->fill([
                'name' => $data['name'] ?? $tariff->name,
                'effective_from' => $from,
                'effective_to' => $to,
                'tariff_category' => $data['tariff_category'] ?? $tariff->tariff_category,
                'state_code' => $data['state_code'] ?? $tariff->state_code,
                'description' => $data['description'] ?? $tariff->description,
                'is_active' => $data['is_active'] ?? $tariff->is_active,
            ]);
            $tariff->save();

            if ($components !== null) {
                $this->syncComponents($tariff, $components);
            }

            return $tariff->fresh('components');
        });
    }

    /**
     * @param  list<array<string, mixed>>  $components
     */
    public function syncComponents(ElectricityTariff $tariff, array $components): void
    {
        ElectricityTariffComponent::query()->where('electricity_tariff_id', $tariff->id)->delete();

        $sort = 0;
        foreach ($components as $row) {
            $type = ElectricityComponentType::from((string) $row['component_type']);
            $basis = ElectricityCalculationBasis::from((string) $row['calculation_basis']);

            ElectricityTariffComponent::query()->create([
                'electricity_tariff_id' => $tariff->id,
                'component_code' => $row['component_code'],
                'component_name' => $row['component_name'],
                'component_type' => $type,
                'calculation_basis' => $basis,
                'rate' => $row['rate'] ?? 0,
                'percentage' => $row['percentage'] ?? 0,
                'slab_config' => $row['slab_config'] ?? null,
                'tod_slot_config' => $row['tod_slot_config'] ?? null,
                'formula_config' => $row['formula_config'] ?? null,
                'unit' => $row['unit'] ?? null,
                'sort_order' => $row['sort_order'] ?? $sort,
                'is_rebate' => (bool) ($row['is_rebate'] ?? false),
                'is_tax' => (bool) ($row['is_tax'] ?? false),
                'is_penalty' => (bool) ($row['is_penalty'] ?? false),
                'is_manual_adjustment' => (bool) ($row['is_manual_adjustment'] ?? false),
                'is_active' => (bool) ($row['is_active'] ?? true),
            ]);
            $sort++;
        }
    }

    private function assertNoOverlap(int $connectionId, string $from, ?string $to, ?int $exceptId = null): void
    {
        if ($to !== null && $to !== '' && $to < $from) {
            throw ValidationException::withMessages(['effective_to' => 'Effective to must be on or after effective from.']);
        }

        $q = ElectricityTariff::query()
            ->where('electricity_connection_id', $connectionId)
            ->where('is_active', true)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->whereDate('effective_from', '<=', $to ?: '9999-12-31')
            ->where(function ($q) use ($from) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from);
            });

        if ($q->exists()) {
            throw ValidationException::withMessages([
                'effective_from' => 'This tariff period overlaps another active tariff for the connection.',
            ]);
        }
    }

    /**
     * @return array<string, list<\Illuminate\Validation\Rules\Enum|string>>
     */
    public static function componentRules(string $prefix = 'components.*'): array
    {
        return [
            "{$prefix}.component_code" => ['required', 'string', 'max:60'],
            "{$prefix}.component_name" => ['required', 'string', 'max:160'],
            "{$prefix}.component_type" => ['required', Rule::enum(ElectricityComponentType::class)],
            "{$prefix}.calculation_basis" => ['required', Rule::enum(ElectricityCalculationBasis::class)],
            "{$prefix}.rate" => ['nullable', 'numeric'],
            "{$prefix}.percentage" => ['nullable', 'numeric'],
            "{$prefix}.slab_config" => ['nullable', 'array'],
            "{$prefix}.tod_slot_config" => ['nullable', 'array'],
            "{$prefix}.formula_config" => ['nullable', 'array'],
            "{$prefix}.unit" => ['nullable', 'string', 'max:20'],
            "{$prefix}.sort_order" => ['nullable', 'integer', 'min:0'],
            "{$prefix}.is_rebate" => ['sometimes', 'boolean'],
            "{$prefix}.is_tax" => ['sometimes', 'boolean'],
            "{$prefix}.is_penalty" => ['sometimes', 'boolean'],
            "{$prefix}.is_manual_adjustment" => ['sometimes', 'boolean'],
            "{$prefix}.is_active" => ['sometimes', 'boolean'],
        ];
    }
}
