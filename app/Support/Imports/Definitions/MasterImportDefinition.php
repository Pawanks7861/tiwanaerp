<?php

namespace App\Support\Imports\Definitions;

use App\Enums\Imports\ImportMode;
use App\Models\Masters\MasterModel;
use App\Enums\Labour\IdProofType;
use App\Models\Masters\EquipmentType;
use App\Models\Masters\LabourTrade;
use App\Models\Masters\MaterialCategory;
use App\Models\Masters\Subcontractor;
use App\Models\Masters\TaxRate;
use App\Models\Masters\Unit;
use App\Models\Masters\Vendor;
use App\Models\Projects\Project;
use App\Services\Masters\MasterService;
use App\Support\Imports\Concerns\NormalizesImportValues;
use App\Support\Imports\ImportContext;
use App\Support\Imports\ImportDefinition;
use App\Support\Imports\ImportField;
use App\Support\Masters\MasterDefinition;
use App\Support\Masters\MasterRegistry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Spreadsheet import for any company master that has a MasterDefinition.
 */
class MasterImportDefinition extends ImportDefinition
{
    use NormalizesImportValues;

    public function __construct(
        private readonly string $masterSlug,
        private readonly MasterService $masters,
    ) {}

    public function master(): MasterDefinition
    {
        return MasterRegistry::get($this->masterSlug);
    }

    public function key(): string
    {
        return 'masters.'.$this->masterSlug;
    }

    public function label(): string
    {
        return $this->master()->title();
    }

    public function permission(): string
    {
        return $this->master()->permission().'.import';
    }

    public function modes(): array
    {
        return [ImportMode::Create, ImportMode::Update, ImportMode::Upsert];
    }

    public function uniqueKey(): ?string
    {
        return 'code';
    }

    public function templateFilename(): string
    {
        return 'Tiwana_ERP_'.str_replace(' ', '', $this->master()->title()).'_Import_Template';
    }

    /**
     * @return list<array{title: string, headings: list<string>, rows: list<list<mixed>>, note?: string}>
     */
    public function referenceData(\App\Models\User $user, ?\App\Models\Projects\Project $project = null): array
    {
        $sheets = parent::referenceData($user, $project);
        $limit = 500;

        if ($this->masterSlug === 'items') {
            $sheets[] = [
                'title' => 'Units',
                'headings' => ['Symbol', 'Name'],
                'rows' => Unit::query()->orderBy('name')->limit($limit)->get(['symbol', 'name'])
                    ->map(fn (Unit $u) => [$u->symbol, $u->name])->all(),
                'note' => 'Use Symbol or Name in the Unit column.',
            ];
            $sheets[] = [
                'title' => 'Categories',
                'headings' => ['Name'],
                'rows' => MaterialCategory::query()->orderBy('name')->limit($limit)->pluck('name')->map(fn ($n) => [$n])->all(),
                'note' => 'Use the category name in the Category column.',
            ];
            $sheets[] = [
                'title' => 'Tax rates',
                'headings' => ['Name', 'Rate %'],
                'rows' => TaxRate::query()->orderBy('name')->limit($limit)->get(['name', 'rate'])
                    ->map(fn (TaxRate $t) => [$t->name, (string) $t->rate])->all(),
                'note' => 'Use the tax rate name in the Tax column.',
            ];
        }

        if ($this->masterSlug === 'equipment') {
            $sheets[] = [
                'title' => 'Equipment types',
                'headings' => ['Name'],
                'rows' => EquipmentType::query()->orderBy('name')->limit($limit)->pluck('name')->map(fn ($n) => [$n])->all(),
            ];
        }

        return $sheets;
    }

    public function fields(): array
    {
        $fields = [];
        foreach ($this->master()->fields() as $field) {
            if (($field['type'] ?? '') === 'switch' && ($field['name'] ?? '') === 'is_active') {
                $fields[] = new ImportField(
                    key: 'is_active',
                    label: 'Active',
                    required: false,
                    aliases: ['status', 'active'],
                    type: 'boolean',
                    allowed: ['Yes', 'No', '1', '0'],
                    help: 'Blank defaults to Yes / active.',
                );

                continue;
            }

            if (in_array($field['type'] ?? '', ['select'], true)) {
                $fields[] = $this->selectField($field);

                continue;
            }

            $fields[] = new ImportField(
                key: $field['name'],
                label: $field['label'],
                required: (bool) ($field['required'] ?? false),
                aliases: $this->aliasesFor($field['name'], $field['label']),
                type: $this->mapType($field['type'] ?? 'text'),
                help: $field['help'] ?? null,
            );
        }

        return $fields;
    }

    public function exampleRows(): array
    {
        return match ($this->masterSlug) {
            'items' => [[
                'code' => 'EXAMPLE-CEMENT',
                'name' => 'Cement OPC 53',
                'item_type' => 'consumable',
                'material_category_id' => 'Cement',
                'unit_id' => 'Bag',
                'tax_rate_id' => 'GST 18%',
                'hsn_sac' => '252329',
                'standard_rate' => '350',
                'reorder_level' => '',
                'description' => 'EXAMPLE ROW — ignored on import',
                'is_active' => 'Yes',
            ]],
            'vendors' => [[
                'code' => 'EXAMPLE-VEND',
                'name' => 'Example Suppliers Pvt Ltd',
                'contact_person' => 'A Sharma',
                'mobile' => '9876543210',
                'email' => 'vendor@example.com',
                'gstin' => '',
                'pan' => '',
                'state_code' => 'HR',
                'address' => 'EXAMPLE ROW',
                'city' => 'Gurugram',
                'pincode' => '122001',
                'payment_terms' => '30 days',
                'bank_name' => '',
                'bank_account_no' => '',
                'bank_ifsc' => '',
                'is_active' => 'Yes',
            ]],
            'subcontractors' => [[
                'code' => 'EXAMPLE-SUB',
                'name' => 'Example Civil Works',
                'trade' => 'Shuttering',
                'contact_person' => 'R Singh',
                'mobile' => '9876543211',
                'email' => '',
                'gstin' => '',
                'pan' => '',
                'state_code' => 'HR',
                'address' => 'EXAMPLE ROW',
                'city' => 'Gurugram',
                'pincode' => '',
                'payment_terms' => '',
                'bank_name' => '',
                'bank_account_no' => '',
                'bank_ifsc' => '',
                'is_active' => 'Yes',
            ]],
            'labour' => [[
                'code' => 'EXAMPLE-LBR',
                'name' => 'Example Labourer',
                'mobile' => '',
                'labour_trade_id' => 'Mason',
                'subcontractor_id' => '',
                'current_project_id' => '',
                'joining_date' => '',
                'daily_wage' => '',
                'ot_rate_per_hour' => '',
                'id_proof_type' => '',
                'id_proof_no' => '',
                'is_active' => 'Yes',
            ]],
            'equipment' => [[
                'code' => 'EXAMPLE-EQ',
                'name' => 'Concrete Mixer',
                'equipment_type_id' => 'Mixer',
                'registration_no' => '',
                'ownership' => 'owned',
                'owner_vendor_id' => '',
                'purchase_date' => '2024-01-15',
                'purchase_value' => '85000',
                'hourly_rate' => '500',
                'daily_rate' => '3500',
                'status' => 'available',
                'remarks' => 'EXAMPLE ROW',
                'is_active' => 'Yes',
            ]],
            default => [[
                'code' => 'EXAMPLE-001',
                'name' => 'Example '.$this->master()->singular(),
                'is_active' => 'Yes',
            ]],
        };
    }

    public function prepareRow(array $mapped, ImportMode $mode, ImportContext $context): array
    {
        if ($this->blankRow($mapped) || $this->isExampleRow($mapped)) {
            return ['action' => 'skip', 'data' => [], 'existing_id' => null, 'preview' => ['name' => '(skipped)']];
        }

        $data = $this->normalizeMapped($mapped);
        $existing = $this->findExisting($data['code'] ?? null);

        $action = match ($mode) {
            ImportMode::Create => 'create',
            ImportMode::Update => 'update',
            ImportMode::Upsert => $existing ? 'update' : 'create',
        };

        if ($action === 'update' && ! $existing) {
            $this->fail(['code' => ['No existing '.$this->master()->singular().' found for this code.']]);
        }

        if ($action === 'create' && $existing) {
            $this->fail(['code' => ['A '.$this->master()->singular().' with this code already exists. Use update or create-and-update mode.']]);
        }

        $record = $action === 'update' ? $existing : null;
        $rules = $this->master()->rules($record);
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $this->master()->prepare($validator->validated(), $record);

        return [
            'action' => $action,
            'data' => $validated,
            'existing_id' => $existing?->id,
            'preview' => [
                'code' => $validated['code'] ?? $existing?->code,
                'name' => $validated['name'] ?? $existing?->name ?? '',
            ],
        ];
    }

    public function commitRow(array $prepared, ImportMode $mode, ImportContext $context): string
    {
        if ($prepared['action'] === 'skip') {
            return 'skipped';
        }

        $definition = $this->master();

        if ($prepared['action'] === 'update') {
            /** @var MasterModel $record */
            $record = $definition->model()::query()->whereKey($prepared['existing_id'])->firstOrFail();
            $this->masters->update($definition, $record, $prepared['data']);

            return 'updated';
        }

        $this->masters->create($definition, $prepared['data']);

        return 'created';
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @return array<string, mixed>
     */
    private function normalizeMapped(array $mapped): array
    {
        $data = [];
        foreach ($this->master()->fields() as $field) {
            $name = $field['name'];
            $raw = $mapped[$name] ?? null;

            $data[$name] = match ($field['type'] ?? 'text') {
                'switch' => $this->boolish($raw, true),
                'number', 'money', 'rate', 'qty', 'percent' => $this->number($raw),
                'date' => $this->date($raw),
                'select' => $this->resolveSelect($name, $raw),
                default => ($field['uppercase'] ?? false)
                    ? ($this->str($raw) !== null ? strtoupper($this->str($raw)) : null)
                    : $this->str($raw),
            };
        }

        if (array_key_exists('is_active', $data) && $data['is_active'] === null) {
            $data['is_active'] = true;
        }

        return $data;
    }

    private function resolveSelect(string $name, mixed $raw): mixed
    {
        $text = $this->str($raw);
        if ($text === null) {
            return null;
        }

        return match ($name) {
            'unit_id' => $this->resolveUnit($text),
            'material_category_id' => $this->resolveCategory($text),
            'tax_rate_id' => $this->resolveTax($text),
            'equipment_type_id' => $this->resolveEquipmentType($text),
            'owner_vendor_id' => $this->resolveVendor($text),
            'labour_trade_id' => $this->resolveLabourTrade($text),
            'subcontractor_id' => $this->resolveSubcontractor($text),
            'current_project_id' => $this->resolveProjectForLabour($text),
            'id_proof_type' => $this->resolveIdProofType($text),
            'item_type', 'ownership', 'status' => strtolower($text),
            'state_code' => strtoupper($text),
            default => is_numeric($text) ? (int) $text : $text,
        };
    }

    private function resolveUnit(string $text): int
    {
        $unit = Unit::query()
            ->where(function ($q) use ($text) {
                $q->whereRaw('LOWER(symbol) = ?', [strtolower($text)])
                    ->orWhereRaw('LOWER(name) = ?', [strtolower($text)]);
            })
            ->first();

        if (! $unit) {
            $this->fail(['unit_id' => ["Unknown unit \"{$text}\". Use an existing unit symbol or name."]]);
        }

        return (int) $unit->id;
    }

    private function resolveCategory(string $text): int
    {
        if (is_numeric($text)) {
            return (int) $text;
        }

        $category = MaterialCategory::query()->whereRaw('LOWER(name) = ?', [strtolower($text)])->first();
        if (! $category) {
            $this->fail(['material_category_id' => ["Unknown category \"{$text}\"."]]);
        }

        return (int) $category->id;
    }

    private function resolveTax(string $text): int
    {
        if (is_numeric($text)) {
            return (int) $text;
        }

        $tax = TaxRate::query()->whereRaw('LOWER(name) = ?', [strtolower($text)])->first();
        if (! $tax) {
            $this->fail(['tax_rate_id' => ["Unknown tax rate \"{$text}\"."]]);
        }

        return (int) $tax->id;
    }

    private function resolveEquipmentType(string $text): int
    {
        if (is_numeric($text)) {
            return (int) $text;
        }

        $type = EquipmentType::query()->whereRaw('LOWER(name) = ?', [strtolower($text)])->first();
        if (! $type) {
            $this->fail(['equipment_type_id' => ["Unknown equipment type \"{$text}\"."]]);
        }

        return (int) $type->id;
    }

    private function resolveVendor(string $text): int
    {
        if (is_numeric($text)) {
            return (int) $text;
        }

        $vendor = Vendor::query()
            ->where(function ($q) use ($text) {
                $q->whereRaw('LOWER(code) = ?', [strtolower($text)])
                    ->orWhereRaw('LOWER(name) = ?', [strtolower($text)]);
            })
            ->first();

        if (! $vendor) {
            $this->fail(['owner_vendor_id' => ["Unknown vendor \"{$text}\"."]]);
        }

        return (int) $vendor->id;
    }

    private function resolveIdProofType(string $text): string
    {
        $normalized = strtolower(str_replace(' ', '_', $text));
        $type = IdProofType::tryFrom($normalized) ?? IdProofType::tryFrom(strtolower($text));
        if (! $type) {
            $this->fail(['id_proof_type' => ["Unknown ID proof type \"{$text}\"."]]);
        }

        return $type->value;
    }

    private function resolveLabourTrade(string $text): int
    {
        if (is_numeric($text)) {
            return (int) $text;
        }

        $trade = LabourTrade::query()->active()->whereRaw('LOWER(name) = ?', [strtolower($text)])->first();
        if (! $trade) {
            $this->fail(['labour_trade_id' => ["Unknown trade \"{$text}\"."]]);
        }

        return (int) $trade->id;
    }

    private function resolveSubcontractor(string $text): int
    {
        if (is_numeric($text)) {
            return (int) $text;
        }

        $sub = Subcontractor::query()->active()
            ->where(function ($q) use ($text) {
                $q->whereRaw('LOWER(code) = ?', [strtolower($text)])
                    ->orWhereRaw('LOWER(name) = ?', [strtolower($text)]);
            })
            ->first();
        if (! $sub) {
            $this->fail(['subcontractor_id' => ["Unknown subcontractor \"{$text}\"."]]);
        }

        return (int) $sub->id;
    }

    private function resolveProjectForLabour(string $text): int
    {
        if (is_numeric($text)) {
            return (int) $text;
        }

        $project = Project::query()
            ->where(function ($q) use ($text) {
                $q->whereRaw('LOWER(code) = ?', [strtolower($text)])
                    ->orWhereRaw('LOWER(name) = ?', [strtolower($text)]);
            })
            ->first();
        if (! $project) {
            $this->fail(['current_project_id' => ["Unknown project \"{$text}\". Use code or name."]]);
        }

        return (int) $project->id;
    }

    private function findExisting(?string $code): ?MasterModel
    {
        if ($code === null || $code === '') {
            return null;
        }

        /** @var class-string<MasterModel> $model */
        $model = $this->master()->model();

        return $model::query()->whereRaw('LOWER(code) = ?', [strtolower($code)])->first();
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function selectField(array $field): ImportField
    {
        $help = match ($field['name']) {
            'unit_id' => 'Unit symbol or name (e.g. Bag, Cum).',
            'material_category_id' => 'Existing category name.',
            'tax_rate_id' => 'Existing tax rate name (e.g. GST 18%).',
            'equipment_type_id' => 'Existing equipment type name.',
            'owner_vendor_id' => 'Vendor code or name (required when ownership is hired).',
            'labour_trade_id' => 'Trade name (e.g. Mason).',
            'subcontractor_id' => 'Subcontractor code or name; blank for departmental labour.',
            'current_project_id' => 'Project code or name for attendance roster.',
            'item_type' => 'material, consumable, asset or service.',
            'ownership' => 'owned or hired.',
            'status' => 'available or disposed.',
            'state_code' => 'Indian state code (e.g. HR, DL).',
            default => $field['help'] ?? 'Use the option label or id.',
        };

        return new ImportField(
            key: $field['name'],
            label: $field['label'],
            required: (bool) ($field['required'] ?? false),
            aliases: $this->aliasesFor($field['name'], $field['label']),
            type: 'select',
            help: $help,
        );
    }

    /**
     * @return list<string>
     */
    private function aliasesFor(string $name, string $label): array
    {
        return match ($name) {
            'code' => ['material_code', 'item_code', 'vendor_code', 'equipment_code'],
            'name' => ['material_name', 'item_name', 'vendor_name', 'equipment_name', 'item_description', 'description_name'],
            'unit_id' => ['unit', 'uom', 'u_o_m'],
            'material_category_id' => ['category', 'material_category'],
            'tax_rate_id' => ['tax', 'gst', 'gst_rate', 'tax_rate'],
            'standard_rate' => ['price', 'rate', 'basic_rate'],
            'item_type' => ['type', 'asset_or_consumable'],
            'equipment_type_id' => ['type', 'equipment_type', 'asset_category'],
            'purchase_value' => ['purchase_cost', 'cost'],
            'mobile' => ['phone', 'contact_phone'],
            'gstin' => ['gst', 'gst_no'],
            'labour_trade_id' => ['trade', 'labour_trade'],
            'subcontractor_id' => ['subcontractor', 'sub_contractor'],
            'current_project_id' => ['project', 'project_code'],
            default => [$label],
        };
    }

    private function mapType(string $type): string
    {
        return match ($type) {
            'number', 'money', 'rate', 'qty', 'percent' => 'number',
            'date' => 'date',
            'switch' => 'boolean',
            'select' => 'select',
            default => 'string',
        };
    }
}
