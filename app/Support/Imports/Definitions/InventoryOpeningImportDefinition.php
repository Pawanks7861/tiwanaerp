<?php

namespace App\Support\Imports\Definitions;

use App\Enums\Imports\ImportMode;
use App\Enums\Inventory\AdjustmentReason;
use App\Models\Inventory\StockAdjustment;
use App\Models\Masters\Material;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Inventory\StockAdjustmentService;
use App\Support\Imports\Concerns\NormalizesImportValues;
use App\Support\Imports\ImportContext;
use App\Support\Imports\ImportDefinition;
use App\Support\Imports\ImportField;
use App\Support\Math\Decimal;
use Illuminate\Validation\ValidationException;

class InventoryOpeningImportDefinition extends ImportDefinition
{
    use NormalizesImportValues;

    public function __construct(private readonly StockAdjustmentService $adjustments) {}

    public function key(): string
    {
        return 'inventory.opening';
    }

    public function label(): string
    {
        return 'Opening stock lines';
    }

    public function permission(): string
    {
        return 'inventory.import';
    }

    public function requiresProject(): bool
    {
        return true;
    }

    public function modes(): array
    {
        return [ImportMode::Create];
    }

    public function uniqueKey(): ?string
    {
        return 'material_code';
    }

    public function authorize(User $user, ?Project $project = null): bool
    {
        if (! $user->can($this->permission()) || ! $user->can('inventory.adjust')) {
            return false;
        }

        return $project === null || $user->can('view', $project);
    }

    public function instructions(): string
    {
        return 'Appends opening-stock lines to a draft adjustment with reason Opening. Each item must not have prior movements in that store. Unit cost is required. Example rows labelled EXAMPLE are ignored.';
    }

    public function templateFilename(): string
    {
        return 'Tiwana_ERP_Inventory_Opening_Stock_Template';
    }

    /**
     * @return list<array{title: string, headings: list<string>, rows: list<list<mixed>>, note?: string}>
     */
    public function referenceData(User $user, ?Project $project = null): array
    {
        return [
            [
                'title' => 'Materials',
                'headings' => ['Code', 'Name'],
                'rows' => Material::query()->orderBy('code')->limit(500)->get(['code', 'name'])
                    ->map(fn (Material $m) => [$m->code, $m->name])->all(),
                'note' => 'Use Code in the Material code column. Only materials without prior stock movements can receive opening qty.',
            ],
        ];
    }

    public function fields(): array
    {
        return [
            new ImportField('material_code', 'Material code', true, ['item_code', 'code']),
            new ImportField('physical_qty', 'Physical quantity', true, ['quantity', 'qty'], 'number'),
            new ImportField('unit_cost', 'Unit cost', true, ['rate', 'cost'], 'number'),
            new ImportField('remarks', 'Remarks', false),
        ];
    }

    public function exampleRows(): array
    {
        return [[
            'material_code' => 'EXAMPLE-CEMENT',
            'physical_qty' => '100',
            'unit_cost' => '350',
            'remarks' => 'EXAMPLE ROW — ignored',
        ]];
    }

    public function prepareRow(array $mapped, ImportMode $mode, ImportContext $context): array
    {
        if ($this->blankRow($mapped) || $this->isExampleRow($mapped)) {
            return ['action' => 'skip', 'data' => [], 'existing_id' => null, 'preview' => ['name' => '(skipped)']];
        }

        $adjustment = $this->adjustment($context);
        $code = $this->str($mapped['material_code'] ?? null);
        if ($code === null) {
            $this->fail(['material_code' => ['Material code is required.']]);
        }

        $material = Material::query()->active()->whereRaw('LOWER(code) = ?', [strtolower($code)])->first();
        if (! $material) {
            $this->fail(['material_code' => ["Unknown material \"{$code}\"."]]);
        }

        $qty = $this->number($mapped['physical_qty'] ?? null);
        if ($qty === null || Decimal::of($qty)->isNegative()) {
            $this->fail(['physical_qty' => ['Enter a valid physical quantity.']]);
        }

        $cost = $this->number($mapped['unit_cost'] ?? null);
        if ($cost === null || ! Decimal::of($cost)->isPositive()) {
            $this->fail(['unit_cost' => ['Opening stock needs a unit cost greater than zero.']]);
        }

        return [
            'action' => 'create',
            'data' => [
                'material_id' => (int) $material->id,
                'physical_qty' => Decimal::of($qty)->toQuantity(),
                'unit_cost' => Decimal::of($cost)->toRate(),
                'remarks' => $this->str($mapped['remarks'] ?? null),
            ],
            'existing_id' => null,
            'preview' => [
                'code' => $material->code,
                'name' => $material->name,
            ],
        ];
    }

    public function commitRow(array $prepared, ImportMode $mode, ImportContext $context): string
    {
        if ($prepared['action'] !== 'create') {
            return 'skipped';
        }

        $bag = $context->remember('opening_bag', fn () => (object) [
            'rows' => [],
            'adjustment' => $this->adjustment($context),
        ]);
        $bag->rows[] = $prepared['data'];

        return 'created';
    }

    public function afterCommit(ImportContext $context): void
    {
        $bag = $context->remember('opening_bag', fn () => null);
        if (! is_object($bag) || $bag->rows === []) {
            return;
        }

        try {
            $this->adjustments->appendLines($bag->adjustment, $bag->rows);
        } catch (ValidationException $e) {
            throw $e;
        }
    }

    private function adjustment(ImportContext $context): StockAdjustment
    {
        $id = (int) $context->meta('stock_adjustment_id');
        if ($id < 1) {
            $this->fail(['stock_adjustment_id' => ['Open Import from a draft opening stock adjustment.']]);
        }

        return $context->remember("stock_adjustment.{$id}", function () use ($id, $context) {
            $adjustment = StockAdjustment::query()->findOrFail($id);
            if ($context->project && (int) $adjustment->project_id !== (int) $context->project->id) {
                $this->fail(['stock_adjustment_id' => ['This adjustment does not belong to the selected project.']]);
            }
            if ($adjustment->reason !== AdjustmentReason::Opening) {
                $this->fail(['stock_adjustment_id' => ['Import is only allowed on an opening stock adjustment.']]);
            }
            if (! $context->actor->can('update', $adjustment)) {
                $this->fail(['stock_adjustment_id' => ['You cannot edit this adjustment.']]);
            }
            $adjustment->assertEditable();

            return $adjustment;
        });
    }
}
