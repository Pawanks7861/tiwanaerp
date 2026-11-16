<?php

namespace App\Support\Imports\Definitions;

use App\Enums\Imports\ImportMode;
use App\Enums\Imports\ImportStatus;
use App\Models\Boq\Boq;
use App\Models\Imports\DataImport;
use App\Models\Masters\Unit;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Boq\BoqImportService;
use App\Support\Imports\Concerns\NormalizesImportValues;
use App\Support\Imports\ImportContext;
use App\Support\Imports\ImportDefinition;
use App\Support\Imports\ImportField;
use Illuminate\Validation\ValidationException;

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
        return 'Upload Excel or CSV. Choose the worksheet and header row if needed, map columns, preview, then confirm. Rows append to a draft BOQ only. Amounts are recalculated as Quantity × Rate.';
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
                'rows' => Unit::query()->orderBy('name')->limit(300)->get(['symbol', 'name'])
                    ->map(fn ($u) => [$u->symbol, $u->name])->all(),
                'note' => 'Unit symbols are matched to company units; unknown units are created automatically.',
            ],
        ];
    }

    public function fields(): array
    {
        return [
            new ImportField('section_name', 'Head', false, [
                'head', 'section', 'boq head', 'category', 'work head', 'sec_name', 'section_name',
            ], help: 'Optional. Standalone head rows without qty/unit are detected automatically.'),
            new ImportField('subsection_name', 'Subhead', false, [
                'subhead', 'sub head', 'subsection', 'subcategory', 'subsection_name',
            ]),
            new ImportField('section_code', 'Section Code', false, ['sec_code']),
            new ImportField('subsection_code', 'Subsection Code', false),
            new ImportField('item_code', 'Item Code', false, [
                'code', 'item code', 'boq code', 'sr code', 'reference', 'item_code',
            ]),
            new ImportField('name', 'Item Description', true, [
                'item', 'item name', 'description', 'description of work', 'work description',
                'particular', 'particulars', 'boq item', 'activity', 'item_name', 'item_description',
                'description of item', 'boq description', 'name',
            ]),
            new ImportField('description', 'Specification / Description', false, [
                'specification', 'spec', 'detailed description', 'notes', 'long_description', 'details',
            ]),
            new ImportField('hsn_sac', 'HSN/SAC', false, [
                'hsn', 'sac', 'hsn/sac', 'hsn code', 'sac code', 'hsn_sac',
            ]),
            new ImportField('unit', 'Unit', true, [
                'unit', 'uom', 'u.o.m.', 'u.o.m', 'measurement unit', 'uom_name',
            ]),
            new ImportField('measurement_nos', 'Nos', false, [
                'nos', 'no', 'numbers', 'measurement_nos', 'no of',
            ], 'number'),
            new ImportField('measurement_length', 'Length', false, [
                'length', 'l', 'measurement_length',
            ], 'number'),
            new ImportField('measurement_width', 'Width', false, [
                'width', 'w', 'breadth', 'measurement_width',
            ], 'number'),
            new ImportField('measurement_height', 'Height', false, [
                'height', 'h', 'depth', 'height/depth', 'measurement_height',
            ], 'number'),
            new ImportField('measurement_mode', 'Measurement Mode', false, [
                'measurement mode', 'mode', 'qty mode', 'measurement_mode',
            ], help: 'auto or manual. Auto recalculates Qty from Nos/L/W/H.'),
            new ImportField('quantity', 'Quantity', true, [
                'qty', 'quantity', 'boq qty', 'total qty',
            ], 'number'),
            new ImportField('client_rate', 'Rate', false, [
                'rate', 'unit rate', 'boq rate', 'price', 'basic_rate', 'client_rate',
            ], 'number'),
            new ImportField('gst_rate', 'GST %', false, [
                'gst', 'gst %', 'tax', 'tax %', 'gst rate', 'gst_rate',
            ], 'number'),
            new ImportField('amount', 'Amount (reference)', false, [
                'amount', 'total', 'line amount', 'value',
            ], 'number', help: 'Mapped for validation only. ERP calculates Amount = Quantity × Rate.'),
            new ImportField('remarks', 'Remarks', false, [
                'remarks', 'remark', 'comments',
            ]),
            new ImportField('material_rate', 'Material Rate', false, ['mat_rate'], 'number'),
            new ImportField('labour_rate', 'Labour Rate', false, [], 'number'),
            new ImportField('equipment_rate', 'Equipment Rate', false, [], 'number'),
            new ImportField('subcontract_rate', 'Subcontract Rate', false, [], 'number'),
            new ImportField('margin_percent', 'Margin Percent', false, ['margin'], 'number'),
        ];
    }

    public function exampleRows(): array
    {
        return [[
            'section_name' => 'Civil Works',
            'item_code' => 'EXAMPLE-001',
            'name' => 'PCC 1:4:8',
            'description' => 'EXAMPLE ROW — ignored',
            'hsn_sac' => '9954',
            'unit' => 'Cum',
            'quantity' => '10',
            'client_rate' => '4500',
            'gst_rate' => '18',
        ]];
    }

    public function prepareRow(array $mapped, ImportMode $mode, ImportContext $context): array
    {
        if ($this->blankRow($mapped) || $this->isExampleRow($mapped)) {
            return ['action' => 'skip', 'data' => [], 'existing_id' => null, 'preview' => [
                'name' => '(blank)',
                'status_label' => 'Skipped',
                'note' => 'Blank row',
            ]];
        }

        foreach (['quantity', 'client_rate', 'gst_rate', 'amount'] as $field) {
            if (isset($mapped[$field]) && $this->isExcelErrorValue($mapped[$field])) {
                $err = strtoupper(trim((string) $mapped[$field]));
                $message = $err === '#FORMULA!'
                    ? 'Formula value unavailable. Open and save the workbook in Excel/LibreOffice, then re-upload.'
                    : ucfirst(str_replace('_', ' ', $field)).' contains Excel error '.$mapped[$field];
                $this->fail([$field => [$message]]);
            }
        }

        // Preserve HSN/item code as strings before numeric helpers touch them.
        if (array_key_exists('hsn_sac', $mapped)) {
            $mapped['hsn_sac'] = $this->codeString($mapped['hsn_sac']);
        }
        if (array_key_exists('item_code', $mapped)) {
            $mapped['item_code'] = $this->codeString($mapped['item_code']);
        }

        if (array_key_exists('gst_rate', $mapped) && $mapped['gst_rate'] !== null && $mapped['gst_rate'] !== '') {
            [$gst, $gstWarning] = $this->percentage($mapped['gst_rate']);
            $mapped['gst_rate'] = $gst;
        } else {
            $gstWarning = null;
        }

        $state = $context->remember('boq_import_state', fn () => (object) [
            'head' => null,
            'subhead' => null,
            'seen_codes' => [],
        ]);

        $classified = $this->boqImport->classifyMappedRow($mapped);

        if ($classified['kind'] === 'blank') {
            return ['action' => 'skip', 'data' => [], 'existing_id' => null, 'preview' => [
                'name' => '(blank)',
                'status_label' => 'Skipped',
                'note' => 'Blank row',
            ]];
        }

        if ($classified['kind'] === 'summary') {
            return ['action' => 'skip', 'data' => [], 'existing_id' => null, 'preview' => [
                'name' => (string) ($mapped['name'] ?? 'Total'),
                'status_label' => 'Summary Row — skipped',
                'note' => 'Summary Row — skipped',
            ]];
        }

        if ($classified['kind'] === 'head') {
            $state->head = $classified['head'];
            $state->subhead = null;

            return ['action' => 'skip', 'data' => [], 'existing_id' => null, 'preview' => [
                'head' => $state->head,
                'name' => $state->head,
                'status_label' => 'Head',
                'note' => 'Head row — subsequent items inherit this head',
            ]];
        }

        if ($classified['kind'] === 'subhead') {
            $state->subhead = $classified['subhead'];

            return ['action' => 'skip', 'data' => [], 'existing_id' => null, 'preview' => [
                'head' => $state->head,
                'name' => $state->subhead,
                'status_label' => 'Subhead',
                'note' => 'Subhead row — subsequent items inherit this subhead',
            ]];
        }

        $this->boq($context)->assertEditable();

        if (($mapped['section_name'] ?? null) === null || trim((string) $mapped['section_name']) === '') {
            $mapped['section_name'] = $state->head ?? 'General';
        } else {
            $state->head = trim((string) $mapped['section_name']);
        }

        if (($mapped['subsection_name'] ?? null) === null || trim((string) $mapped['subsection_name']) === '') {
            if ($state->subhead) {
                $mapped['subsection_name'] = $state->subhead;
            }
        } else {
            $state->subhead = trim((string) $mapped['subsection_name']);
        }

        $result = $this->boqImport->validateMappedRow($mapped);
        $parsed = $result['row'];
        $warnings = $result['warnings'];
        if ($gstWarning) {
            $warnings[] = $gstWarning;
        }

        $code = $parsed['item_code'] ?? null;
        if ($code !== null && $code !== '') {
            $fingerprint = strtolower($code);
            if (isset($state->seen_codes[$fingerprint])) {
                $warnings[] = "Duplicate item code \"{$code}\" also appears earlier in this file.";
            }
            $state->seen_codes[$fingerprint] = true;
        } else {
            $warnings[] = 'Missing item code';
        }

        if (($parsed['gst_rate'] ?? null) === null) {
            $warnings[] = 'GST blank';
        }

        $qty = (float) ($parsed['quantity'] ?? 0);
        $rate = (float) ($parsed['client_rate'] ?? 0);
        $calcAmount = round($qty * $rate, 2);

        return [
            'action' => 'create',
            'data' => $parsed,
            'existing_id' => null,
            'preview' => [
                'head' => $parsed['section_name'] ?? null,
                'code' => $code,
                'name' => $parsed['name'] ?? null,
                'unit' => $mapped['unit'] ?? null,
                'quantity' => $parsed['quantity'] ?? null,
                'rate' => $parsed['client_rate'] ?? null,
                'gst' => $parsed['gst_rate'] ?? null,
                'amount' => number_format($calcAmount, 2, '.', ''),
                'status_label' => $warnings === [] ? 'Valid' : 'Warning',
                'warnings' => $warnings,
            ],
            'warnings' => $warnings,
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

        $row = $prepared['data'];
        $importId = (int) $context->meta('import_id');
        $row['source_type'] = 'import';
        $row['source_import_id'] = $importId > 0 ? $importId : null;
        $row['source_sheet'] = $context->meta('sheet_name');
        $row['source_row'] = $prepared['source_row'] ?? null;

        $bag->rows[] = $row;

        return 'created';
    }

    public function afterCommit(ImportContext $context): void
    {
        $bag = $context->remember('boq_bag', fn () => null);
        if (! is_object($bag) || $bag->rows === []) {
            return;
        }

        $replaceId = (int) ($context->meta('replace_import_id', 0) ?: $context->remember('replace_import_id_resolved', fn () => 0));
        if ($replaceId > 0) {
            $this->boqImport->replaceImportBatch($bag->boq, $replaceId);
        }

        $this->boqImport->appendRows($bag->boq, $bag->rows, (bool) $bag->canEditCosts);

        $importId = (int) $context->meta('import_id');
        if ($importId > 0) {
            $bag->boq->forceFill(['source_import_id' => $importId])->save();
        }
    }

    /**
     * Guard re-import of the same source file into the same draft BOQ.
     */
    public function assertReimportAllowed(ImportContext $context, DataImport $import): void
    {
        $action = (string) $context->meta('reimport_action', '');
        if (in_array($action, ['additional', 'replace'], true)) {
            if ($action === 'replace') {
                $previous = $this->previousCompletedImport($context, $import);
                if ($previous) {
                    // Stash for afterCommit; meta is readonly — use remember.
                    $context->remember('replace_import_id_resolved', fn () => $previous->id);
                }
            }

            return;
        }

        $previous = $this->previousCompletedImport($context, $import);
        if ($previous) {
            throw ValidationException::withMessages([
                'import' => 'This file has already been imported into this BOQ. Choose Cancel, Import as additional rows, or Replace previous draft import.',
            ]);
        }
    }

    private function previousCompletedImport(ImportContext $context, DataImport $import): ?DataImport
    {
        $boqId = (int) $context->meta('boq_id');
        if ($boqId < 1) {
            return null;
        }

        return DataImport::query()
            ->where('module', $this->key())
            ->where('company_id', $import->company_id)
            ->where('id', '!=', $import->id)
            ->where('original_filename', $import->original_filename)
            ->whereIn('status', [ImportStatus::Completed->value, ImportStatus::CompletedWithErrors->value])
            ->where('context->boq_id', $boqId)
            ->latest('id')
            ->first();
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
