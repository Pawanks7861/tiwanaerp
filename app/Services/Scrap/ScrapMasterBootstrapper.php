<?php

namespace App\Services\Scrap;

use App\Models\Core\Company;
use App\Models\Masters\ScrapCategory;
use App\Models\Masters\ScrapType;
use App\Models\Masters\Unit;

/**
 * Default scrap categories and types for a company (idempotent by company + code).
 */
class ScrapMasterBootstrapper
{
    public function seedForCompany(Company $company): void
    {
        $units = Unit::query()->pluck('id', 'symbol');

        $resolve = fn (string $symbol): ?int => $units->get($symbol);

        $categories = [
            ['code' => 'METALS', 'name' => 'Metals', 'sort_order' => 10],
            ['code' => 'PLASTIC', 'name' => 'Plastic', 'sort_order' => 20],
            ['code' => 'BAGS', 'name' => 'Bags / Packing', 'sort_order' => 30],
            ['code' => 'OIL', 'name' => 'Oil', 'sort_order' => 40],
            ['code' => 'ELECTRICAL', 'name' => 'Electrical', 'sort_order' => 50],
            ['code' => 'DEBRIS', 'name' => 'Construction Debris', 'sort_order' => 60],
        ];

        // Exact Phase 1 catalogue — admin may edit later; never hard-code these in Vue/Gate forms.
        $types = [
            'METALS' => [
                ['code' => 'MS', 'name' => 'MS', 'unit' => 'Kg'],
                ['code' => 'SS', 'name' => 'SS', 'unit' => 'Kg'],
                ['code' => 'CI', 'name' => 'CI', 'unit' => 'Kg'],
                ['code' => 'GI', 'name' => 'GI', 'unit' => 'Kg'],
                ['code' => 'COPPER', 'name' => 'Copper', 'unit' => 'Kg'],
                ['code' => 'ALUMINUM', 'name' => 'Aluminum', 'unit' => 'Kg'],
                ['code' => 'BRASS', 'name' => 'Brass', 'unit' => 'Kg'],
            ],
            'PLASTIC' => [
                ['code' => 'HDPE', 'name' => 'HDPE', 'unit' => 'Kg'],
                ['code' => 'PP', 'name' => 'PP', 'unit' => 'Kg'],
                ['code' => 'PVC', 'name' => 'PVC', 'unit' => 'Kg'],
            ],
            'BAGS' => [
                ['code' => 'PP_BAGS', 'name' => 'PP Bags', 'unit' => 'Nos'],
                ['code' => 'HDPE_BAGS', 'name' => 'HDPE Bags', 'unit' => 'Nos'],
                ['code' => 'JUTE_BAGS', 'name' => 'Jute Bags', 'unit' => 'Nos'],
                ['code' => 'CARTONS', 'name' => 'Cartons', 'unit' => 'Nos'],
                ['code' => 'WOODEN', 'name' => 'Wooden', 'unit' => 'Nos'],
            ],
            'OIL' => [
                ['code' => 'OIL', 'name' => 'Oil', 'unit' => 'Ltr'],
                ['code' => 'HSD', 'name' => 'HSD', 'unit' => 'Ltr'],
                ['code' => 'LUBRICANT', 'name' => 'Lubricant', 'unit' => 'Ltr'],
                ['code' => 'MOLASSES', 'name' => 'Molasses', 'unit' => 'Ltr'],
                ['code' => 'VEG_OIL', 'name' => 'Veg Oil', 'unit' => 'Ltr'],
            ],
            'ELECTRICAL' => [
                ['code' => 'BATTERIES', 'name' => 'Batteries', 'unit' => 'Nos'],
                ['code' => 'E_WASTE', 'name' => 'E-Waste', 'unit' => 'Kg'],
                ['code' => 'ELEC_INSULATION', 'name' => 'Electrical Insulation', 'unit' => 'Kg'],
                ['code' => 'AL_CABLES', 'name' => 'Aluminum Cables', 'unit' => 'Kg'],
                ['code' => 'CU_CABLES', 'name' => 'Copper Cables', 'unit' => 'Kg'],
            ],
            'DEBRIS' => [
                ['code' => 'CONSTRUCTION_DEBRIS', 'name' => 'Construction Debris', 'unit' => 'Cum'],
            ],
        ];

        foreach ($categories as $cat) {
            $category = ScrapCategory::query()->firstOrCreate(
                ['company_id' => $company->id, 'code' => $cat['code']],
                ['name' => $cat['name'], 'sort_order' => $cat['sort_order'], 'is_active' => true],
            );

            foreach ($types[$cat['code']] ?? [] as $order => $type) {
                ScrapType::query()->firstOrCreate(
                    [
                        'company_id' => $company->id,
                        'scrap_category_id' => $category->id,
                        'code' => $type['code'],
                    ],
                    [
                        'name' => $type['name'],
                        'unit_id' => $resolve($type['unit']),
                        'sort_order' => $order + 1,
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
