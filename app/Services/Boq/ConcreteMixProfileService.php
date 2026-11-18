<?php

namespace App\Services\Boq;

use App\Models\Boq\BoqItem;
use App\Models\Boq\ConcreteMixProfile;
use App\Models\Boq\ConcreteMixProfileLine;
use App\Models\Core\Company;
use App\Models\Masters\Material;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Simple/Normal BOQ concrete mix factor profiles (PCC M-10, RCC M-25, extensible).
 * Does not affect Advanced BOQ workbook costing.
 */
class ConcreteMixProfileService
{
    /** @var list<array{name:string,concrete_type:string,grade:string,sort_order:int,lines:list<array{material_name:string,factor:string,factor_unit:string}>}> */
    public const DEFAULTS = [
        [
            'name' => 'PCC — M-10',
            'concrete_type' => 'pcc',
            'grade' => 'M10',
            'sort_order' => 10,
            'lines' => [
                ['material_name' => 'Cement', 'factor' => '3.4100', 'factor_unit' => 'Bag'],
                ['material_name' => 'Sand', 'factor' => '0.4740', 'factor_unit' => 'Cum'],
                ['material_name' => 'Aggregate', 'factor' => '0.9480', 'factor_unit' => 'Cum'],
            ],
        ],
        [
            'name' => 'RCC — M-25',
            'concrete_type' => 'rcc',
            'grade' => 'M25',
            'sort_order' => 20,
            'lines' => [
                ['material_name' => 'Cement', 'factor' => '7.3000', 'factor_unit' => 'Bag'],
                ['material_name' => 'Sand', 'factor' => '0.3850', 'factor_unit' => 'Cum'],
                ['material_name' => 'Aggregate', 'factor' => '0.7700', 'factor_unit' => 'Cum'],
            ],
        ],
    ];

    public function ensureDefaults(Company $company): void
    {
        DB::transaction(function () use ($company) {
            foreach (self::DEFAULTS as $def) {
                $profile = ConcreteMixProfile::query()
                    ->withoutGlobalScopes()
                    ->where('company_id', $company->id)
                    ->where('name', $def['name'])
                    ->first();

                if ($profile === null) {
                    $profile = new ConcreteMixProfile;
                    $profile->forceFill([
                        'company_id' => $company->id,
                        'name' => $def['name'],
                        'concrete_type' => $def['concrete_type'],
                        'grade' => $def['grade'],
                        'boq_unit_symbol' => 'Cum',
                        'is_active' => true,
                        'is_system' => true,
                        'sort_order' => $def['sort_order'],
                    ])->save();
                }

                if ($profile->lines()->exists()) {
                    continue;
                }

                foreach ($def['lines'] as $i => $line) {
                    $materialId = Material::query()
                        ->withoutGlobalScopes()
                        ->where('company_id', $company->id)
                        ->where('name', $line['material_name'])
                        ->value('id');

                    $row = new ConcreteMixProfileLine;
                    $row->forceFill([
                        'concrete_mix_profile_id' => $profile->id,
                        'material_id' => $materialId,
                        'material_name' => $line['material_name'],
                        'factor' => Decimal::of($line['factor'])->toQuantity(),
                        'factor_unit' => $line['factor_unit'],
                        'sort_order' => ($i + 1) * 10,
                    ])->save();
                }
            }
        });
    }

    /**
     * @return list<array{material_id:?int,material_name:string,quantity:string,unit:string,factor:string}>
     */
    public function requiredMaterials(BoqItem $item, ?ConcreteMixProfile $profile = null): array
    {
        $profile ??= $item->concreteMixProfile;
        $snapshot = $item->concrete_mix_profile_snapshot;
        $lines = is_array($snapshot['lines'] ?? null)
            ? $snapshot['lines']
            : ($profile?->lines?->all() ?? []);

        $qty = Decimal::of($item->quantity ?? '0');
        $out = [];
        foreach ($lines as $i => $line) {
            $factor = Decimal::of((string) (is_array($line) ? ($line['factor'] ?? '0') : $line->factor));
            $name = (string) (is_array($line) ? ($line['material_name'] ?? '') : $line->material_name);
            $unit = (string) (is_array($line) ? ($line['factor_unit'] ?? '') : $line->factor_unit);
            $materialId = is_array($line) ? ($line['material_id'] ?? null) : $line->material_id;
            $out[] = [
                'material_id' => $materialId !== null ? (int) $materialId : null,
                'material_name' => $name,
                'quantity' => $qty->times($factor)->toQuantity(),
                'unit' => $unit,
                'factor' => $factor->toQuantity(),
                'sequence' => $i,
            ];
        }

        return $out;
    }

    /**
     * @return array{id:int,name:string,concrete_type:string,grade:?string,lines:list<array<string,mixed>>}
     */
    public function snapshot(ConcreteMixProfile $profile): array
    {
        $profile->loadMissing('lines');

        return [
            'id' => (int) $profile->id,
            'name' => $profile->name,
            'concrete_type' => $profile->concrete_type,
            'grade' => $profile->grade,
            'lines' => $profile->lines->map(fn (ConcreteMixProfileLine $line) => [
                'material_id' => $line->material_id ? (int) $line->material_id : null,
                'material_name' => $line->material_name,
                'factor' => (string) $line->factor,
                'factor_unit' => $line->factor_unit,
                'sort_order' => (int) $line->sort_order,
            ])->values()->all(),
        ];
    }

    public function applyToItem(BoqItem $item, ConcreteMixProfile $profile): void
    {
        $item->forceFill([
            'concrete_mix_profile_id' => $profile->id,
            'concrete_mix_profile_snapshot' => $this->snapshot($profile),
        ])->save();
    }
}
