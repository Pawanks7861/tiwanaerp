<?php

namespace App\Support\Imports\Definitions;

use App\Enums\Imports\ImportMode;
use App\Models\Boq\Boq;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Boq\BoqImportService;
use App\Support\Imports\Concerns\NormalizesImportValues;
use App\Support\Imports\ImportContext;
use App\Support\Imports\ImportDefinition;
use App\Support\Imports\ImportField;

class BoqItemsImportDefinition extends ImportDefinition
{
    use NormalizesImportValues;

    public function __construct(private readonly BoqImportService $boqImport) {}

    public function key(): string
    {
        return 'boq.items';
    }

    public function label(): string
    {
        return 'BOQ items';
    }

    public function permission(): string
    {
        return 'boq.import';
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
        return null;
    }

    public function authorize(User $user, ?Project $project = null): bool
    {
        if (! $user->can($this->permission()) || ! $user->can('boq.update')) {
            return false;
        }

        return $project === null || $user->can('view', $project);
    }

    public function instructions(): string
    {
        return 'Appends lines to an editable draft or rejected BOQ. Totals use the existing BOQ calculator. Approved or locked BOQs cannot be imported into. Example rows labelled EXAMPLE are ignored.';
    }

    public function templateFilename(): string
    {
        return 'Tiwana_ERP_BOQ_Import_Template';
    }

    /**
     * @return list<array{title: string, headings: list<string>, rows: list<list<mixed>>, note?: string}>
     */
    public function referenceData(User $user, ?Project $project = null): array
    {
        return [
            [
                'title' => 'Units',
                'headings' => ['Symbol', 'Name'],
                'rows' => \App\Models\Masters\Unit::query()->orderBy('name')->limit(300)->get(['symbol', 'name'])
                    ->map(fn ($u) => [$u->symbol, $u->name])->all(),
                'note' => 'Unit must match an active company unit symbol or name.',
            ],
        ];
    }

    public function fields(): array
    {
        return [
            new ImportField('section_code', 'Section Code', false, ['sec_code']),
            new ImportField('section_name', 'Section Name', true, ['section', 'sec_name']),
            new ImportField('subsection_code', 'Subsection Code', false),
            new ImportField('subsection_name', 'Subsection Name', false, ['subsection']),
            new ImportField('item_code', 'Item Code', false, ['code']),
            new ImportField('name', 'Name', true, ['item_name', 'item_description']),
            new ImportField('description', 'Description', false, ['long_description', 'details']),
            new ImportField('hsn_sac', 'HSN SAC', false, ['hsn', 'sac']),
            new ImportField('unit', 'Unit', true, ['uom', 'uom_name']),
            new ImportField('quantity', 'Quantity', true, ['qty'], 'number'),
            new ImportField('material_rate', 'Material Rate', false, ['mat_rate'], 'number'),
            new ImportField('labour_rate', 'Labour Rate', false, [], 'number'),
            new ImportField('equipment_rate', 'Equipment Rate', false, [], 'number'),
            new ImportField('subcontract_rate', 'Subcontract Rate', false, [], 'number'),
            new ImportField('margin_percent', 'Margin Percent', false, ['margin'], 'number'),
            new ImportField('client_rate', 'Client Rate', false, ['rate', 'basic_rate'], 'number'),
        ];
    }

    public function exampleRows(): array
    {
        return [[
            'section_code' => 'A',
            'section_name' => 'Civil Works',
            'subsection_code' => '',
            'subsection_name' => '',
            'item_code' => 'EXAMPLE-001',
            'name' => 'PCC 1:4:8',
            'description' => 'EXAMPLE ROW — ignored',
            'hsn_sac' => '',
            'unit' => 'Cum',
            'quantity' => '10',
            'material_rate' => '3500',
            'labour_rate' => '500',
            'equipment_rate' => '0',
            'subcontract_rate' => '0',
            'margin_percent' => '10',
            'client_rate' => '',
        ]];
    }

    public function prepareRow(array $mapped, ImportMode $mode, ImportContext $context): array
    {
        if ($this->blankRow($mapped) || $this->isExampleRow($mapped)) {
            return ['action' => 'skip', 'data' => [], 'existing_id' => null, 'preview' => ['name' => '(skipped)']];
        }

        $this->boq($context)->assertEditable();
        $parsed = $this->boqImport->validateMappedRow($mapped);

        return [
            'action' => 'create',
            'data' => $parsed,
            'existing_id' => null,
            'preview' => [
                'code' => $parsed['item_code'] ?? null,
                'name' => $parsed['name'] ?? null,
            ],
        ];
    }

    public function commitRow(array $prepared, ImportMode $mode, ImportContext $context): string
    {
        if ($prepared['action'] !== 'create') {
            return 'skipped';
        }

        $bag = $context->remember('boq_bag', fn () => (object) [
            'rows' => [],
            'boq' => $this->boq($context),
            'canEditCosts' => $context->actor->can('boq.view_costs'),
        ]);
        $bag->rows[] = $prepared['data'];

        return 'created';
    }

    public function afterCommit(ImportContext $context): void
    {
        $bag = $context->remember('boq_bag', fn () => null);
        if (! is_object($bag) || $bag->rows === []) {
            return;
        }

        $this->boqImport->appendRows($bag->boq, $bag->rows, (bool) $bag->canEditCosts);
    }

    private function boq(ImportContext $context): Boq
    {
        $boqId = (int) $context->meta('boq_id');
        if ($boqId < 1) {
            $this->fail(['boq_id' => ['Open Import from an editable BOQ.']]);
        }

        return $context->remember("boq.{$boqId}", function () use ($boqId, $context) {
            $boq = Boq::query()->findOrFail($boqId);
            if ($context->project && (int) $boq->project_id !== (int) $context->project->id) {
                $this->fail(['boq_id' => ['This BOQ does not belong to the selected project.']]);
            }
            $boq->assertEditable();

            return $boq;
        });
    }
}
