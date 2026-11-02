<?php

namespace App\Support\Imports;

use App\Services\Boq\BoqImportService;
use App\Services\Finance\ExpenseService;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\Masters\MasterService;
use App\Services\Planning\PlanningService;
use App\Services\Planning\TaskDependencyService;
use App\Services\Procurement\MaterialRequestImportService;
use App\Services\Procurement\MaterialRequestService;
use App\Services\Projects\ProjectService;
use App\Services\Quality\QualityChecklistService;
use App\Support\Imports\Definitions\BoqItemsImportDefinition;
use App\Support\Imports\Definitions\ExpenseImportDefinition;
use App\Support\Imports\Definitions\InventoryOpeningImportDefinition;
use App\Support\Imports\Definitions\LabourRegisterImportDefinition;
use App\Support\Imports\Definitions\MasterImportDefinition;
use App\Support\Imports\Definitions\MaterialRequestItemsImportDefinition;
use App\Support\Imports\Definitions\PlanningTaskImportDefinition;
use App\Support\Imports\Definitions\ProjectImportDefinition;
use App\Support\Imports\Definitions\QualityChecklistImportDefinition;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ImportCatalog
{
    /** Masters that support spreadsheet import in Stage B. */
    private const MASTER_SLUGS = [
        'items',
        'vendors',
        'subcontractors',
        'equipment',
    ];

    /**
     * @return array<string, ImportDefinition>
     */
    public static function all(): array
    {
        static $definitions = null;

        if ($definitions !== null) {
            return $definitions;
        }

        $definitions = [];

        $definitions['projects'] = new ProjectImportDefinition(app(ProjectService::class));

        $masters = app(MasterService::class);
        foreach (self::MASTER_SLUGS as $slug) {
            $definition = new MasterImportDefinition($slug, $masters);
            $definitions[$definition->key()] = $definition;
        }

        $definitions['boq.items'] = new BoqItemsImportDefinition(app(BoqImportService::class));
        $definitions['planning.tasks'] = new PlanningTaskImportDefinition(
            app(PlanningService::class),
            app(TaskDependencyService::class),
        );
        $definitions['quality.checklists'] = new QualityChecklistImportDefinition(app(QualityChecklistService::class));

        $definitions['material_requests.items'] = new MaterialRequestItemsImportDefinition(
            app(MaterialRequestImportService::class),
            app(MaterialRequestService::class),
        );
        $definitions['inventory.opening'] = new InventoryOpeningImportDefinition(app(StockAdjustmentService::class));
        $definitions['expenses'] = new ExpenseImportDefinition(app(ExpenseService::class));
        $definitions['labour'] = new LabourRegisterImportDefinition(app(MasterService::class));

        return $definitions;
    }

    public static function get(string $key): ImportDefinition
    {
        $definitions = self::all();
        if (! isset($definitions[$key])) {
            throw new NotFoundHttpException("Import module [{$key}] is not available.");
        }

        return $definitions[$key];
    }

    /**
     * @return list<array{key: string, label: string, permission: string, requires_project: bool, modes: list<array{value: string, label: string}>}>
     */
    public static function availableFor(\App\Models\User $user): array
    {
        $items = [];
        foreach (self::all() as $definition) {
            if (! $definition->authorize($user)) {
                continue;
            }
            $items[] = [
                'key' => $definition->key(),
                'label' => $definition->label(),
                'permission' => $definition->permission(),
                'requires_project' => $definition->requiresProject(),
                'modes' => $definition->modeOptions(),
            ];
        }

        return $items;
    }
}
