<?php

namespace App\Services\Boq;

use App\Models\Boq\UnitConversion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnitConversionService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function save(int $companyId, array $data, ?UnitConversion $conversion = null, ?User $user = null): UnitConversion
    {
        return DB::transaction(function () use ($companyId, $data, $conversion, $user) {
            $from = trim((string) ($data['from_unit'] ?? ''));
            $to = trim((string) ($data['to_unit'] ?? ''));
            if ($from === '' || $to === '') {
                throw ValidationException::withMessages(['from_unit' => 'From and to units are required.']);
            }
            if (mb_strtolower($from) === mb_strtolower($to)) {
                throw ValidationException::withMessages(['to_unit' => 'From and to units must differ.']);
            }

            $conversion ??= new UnitConversion;
            $conversion->forceFill([
                'company_id' => $companyId,
                'project_id' => $data['project_id'] ?? null,
                'material_id' => $data['material_id'] ?? null,
                'from_unit' => $from,
                'to_unit' => $to,
                'operation' => $data['operation'],
                'factor' => (string) $data['factor'],
                'description' => $data['description'] ?? null,
                'effective_from' => $data['effective_from'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'updated_by' => $user?->id,
                'created_by' => $conversion->created_by ?? $user?->id,
            ])->save();

            return $conversion;
        });
    }

    /**
     * Resolve conversion: material-specific → project → company.
     *
     * @return array{result: string, derivation_trace: string, conversion: array<string, mixed>}
     */
    public function convert(
        int $companyId,
        ?int $projectId,
        ?int $materialId,
        string $fromUnit,
        string $toUnit,
        string $quantity,
    ): array {
        $rule = $this->resolveRule($companyId, $projectId, $materialId, $fromUnit, $toUnit)
            ?? throw ValidationException::withMessages([
                'conversion' => "No active conversion from {$fromUnit} to {$toUnit}.",
            ]);

        $calc = UnitConversionCalculator::convert([
            'quantity' => $quantity,
            'operation' => $rule->operation,
            'factor' => (string) $rule->factor,
        ]);

        return [
            'result' => $calc['result'],
            'derivation_trace' => $calc['derivation_trace'],
            'conversion' => [
                'id' => $rule->id,
                'from_unit' => $rule->from_unit,
                'to_unit' => $rule->to_unit,
                'operation' => $rule->operation->value,
                'factor' => (string) $rule->factor,
                'material_id' => $rule->material_id,
                'project_id' => $rule->project_id,
            ],
        ];
    }

    public function resolveRule(
        int $companyId,
        ?int $projectId,
        ?int $materialId,
        string $fromUnit,
        string $toUnit,
    ): ?UnitConversion {
        $from = mb_strtolower(trim($fromUnit));
        $to = mb_strtolower(trim($toUnit));

        $base = UnitConversion::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereRaw('LOWER(from_unit) = ?', [$from])
            ->whereRaw('LOWER(to_unit) = ?', [$to]);

        if ($materialId) {
            $materialRule = (clone $base)->where('material_id', $materialId)->orderByDesc('id')->first();
            if ($materialRule) {
                return $materialRule;
            }
        }

        if ($projectId) {
            $projectRule = (clone $base)
                ->where('project_id', $projectId)
                ->whereNull('material_id')
                ->orderByDesc('id')
                ->first();
            if ($projectRule) {
                return $projectRule;
            }
        }

        return (clone $base)
            ->whereNull('project_id')
            ->whereNull('material_id')
            ->orderByDesc('id')
            ->first();
    }
}
