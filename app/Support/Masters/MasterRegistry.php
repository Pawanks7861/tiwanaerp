<?php

namespace App\Support\Masters;

use App\Support\Masters\Definitions\CategoryDefinition;
use App\Support\Masters\Definitions\ClientDefinition;
use App\Support\Masters\Definitions\EquipmentDefinition;
use App\Support\Masters\Definitions\EquipmentTypeDefinition;
use App\Support\Masters\Definitions\ExpenseCategoryDefinition;
use App\Support\Masters\Definitions\ItemDefinition;
use App\Support\Masters\Definitions\LabourDefinition;
use App\Support\Masters\Definitions\LabourTradeDefinition;
use App\Support\Masters\Definitions\SubcontractorDefinition;
use App\Support\Masters\Definitions\TaxRateDefinition;
use App\Support\Masters\Definitions\UnitDefinition;
use App\Support\Masters\Definitions\VendorDefinition;
use App\Support\Masters\Definitions\WarehouseDefinition;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MasterRegistry
{
    /** @var list<class-string<MasterDefinition>> */
    private const DEFINITIONS = [
        ItemDefinition::class,
        CategoryDefinition::class,
        UnitDefinition::class,
        TaxRateDefinition::class,
        VendorDefinition::class,
        SubcontractorDefinition::class,
        ClientDefinition::class,
        LabourTradeDefinition::class,
        EquipmentTypeDefinition::class,
        WarehouseDefinition::class,
        ExpenseCategoryDefinition::class,
        LabourDefinition::class,
        EquipmentDefinition::class,
    ];

    /**
     * @return array<string, MasterDefinition>
     */
    public static function all(): array
    {
        static $definitions = null;

        if ($definitions === null) {
            $definitions = [];
            foreach (self::DEFINITIONS as $class) {
                $definition = new $class;
                $definitions[$definition->slug()] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::all());
    }

    public static function get(string $slug): MasterDefinition
    {
        return self::all()[$slug] ?? throw new NotFoundHttpException;
    }
}
