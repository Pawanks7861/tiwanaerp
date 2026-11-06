<?php

namespace App\Support\Imports\Definitions;

use App\Enums\Imports\ImportMode;
use App\Models\Procurement\MaterialRequest;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Procurement\MaterialRequestImportService;
use App\Services\Procurement\MaterialRequestService;
use App\Support\Imports\Concerns\NormalizesImportValues;
use App\Support\Imports\ImportContext;
use App\Support\Imports\ImportDefinition;
use App\Support\Imports\ImportField;

class MaterialRequestItemsImportDefinition extends ImportDefinition
{
    use NormalizesImportValues;

    public function __construct(
        private readonly MaterialRequestImportService $import,
        private readonly MaterialRequestService $requests,
    ) {}

    public function key(): string
    {
        return 'material_requests.items';
    }

    public function label(): string
    {
        return 'Material request lines';
    }

    public function permission(): string
    {
        return 'material_requests.import';
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
        if (! $user->can($this->permission()) || ! $user->can('material_requests.update')) {
            return false;
        }

        return $project === null || $user->can('view', $project);
    }

    public function templateFilename(): string
    {
        return 'Tiwana_ERP_Purchase_Request_Items_Import_Template';
    }

    public function instructions(): string
    {
        return 'Appends lines to an editable draft material request. No rates or amounts. Example rows labelled EXAMPLE are ignored.';
    }

    public function fields(): array
    {
        return [
            new ImportField('material_code', 'Material code', true, ['item_code', 'code', 'material']),
            new ImportField('quantity', 'Quantity', true, ['qty'], 'number'),
            new ImportField('unit', 'Unit', false, ['uom'], help: 'Optional; defaults to the material unit.'),
            new ImportField('task_wbs', 'Task WBS', false, ['wbs', 'wbs_code']),
            new ImportField('remarks', 'Remarks', false),
        ];
    }

    public function exampleRows(): array
    {
        return [[
            'material_code' => 'EXAMPLE-CEMENT',
            'quantity' => '10',
            'unit' => '',
            'task_wbs' => '',
            'remarks' => 'EXAMPLE ROW — ignored',
        ]];
    }

    public function prepareRow(array $mapped, ImportMode $mode, ImportContext $context): array
    {
        if ($this->blankRow($mapped) || $this->isExampleRow($mapped)) {
            return ['action' => 'skip', 'data' => [], 'existing_id' => null, 'preview' => ['name' => '(skipped)']];
        }

        $project = $context->project ?? $this->fail(['project_id' => ['This import requires a project.']]);
        $this->materialRequest($context)->assertEditable();
        $parsed = $this->import->validateMappedRow($project, $mapped);

        return [
            'action' => 'create',
            'data' => $parsed,
            'existing_id' => null,
            'preview' => [
                'code' => $mapped['material_code'] ?? null,
                'name' => $parsed['quantity'].' × material #'.$parsed['material_id'],
            ],
        ];
    }

    public function commitRow(array $prepared, ImportMode $mode, ImportContext $context): string
    {
        if ($prepared['action'] !== 'create') {
            return 'skipped';
        }

        $bag = $context->remember('mr_bag', fn () => (object) [
            'rows' => [],
            'mr' => $this->materialRequest($context),
        ]);
        $bag->rows[] = $prepared['data'];

        return 'created';
    }

    public function afterCommit(ImportContext $context): void
    {
        $bag = $context->remember('mr_bag', fn () => null);
        if (! is_object($bag) || $bag->rows === []) {
            return;
        }

        $this->requests->appendItems($bag->mr, $bag->rows);
    }

    private function materialRequest(ImportContext $context): MaterialRequest
    {
        $id = (int) $context->meta('material_request_id');
        if ($id < 1) {
            $this->fail(['material_request_id' => ['Open Import from an editable material request.']]);
        }

        return $context->remember("material_request.{$id}", function () use ($id, $context) {
            $mr = MaterialRequest::query()->findOrFail($id);
            if ($context->project && (int) $mr->project_id !== (int) $context->project->id) {
                $this->fail(['material_request_id' => ['This material request does not belong to the selected project.']]);
            }
            if (! $context->actor->can('update', $mr)) {
                $this->fail(['material_request_id' => ['You cannot edit this material request.']]);
            }
            $mr->assertEditable();

            return $mr;
        });
    }
}
