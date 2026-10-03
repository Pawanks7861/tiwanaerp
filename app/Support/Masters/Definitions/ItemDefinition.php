<?php

namespace App\Support\Masters\Definitions;

use App\Enums\ItemType;
use App\Models\Masters\Material;
use App\Models\Masters\MaterialCategory;
use App\Models\Masters\TaxRate;
use App\Models\Masters\Unit;
use App\Rules\ExistsInCompany;
use App\Support\Masters\IndianFormats;
use App\Support\Masters\MasterDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ItemDefinition extends MasterDefinition
{
    public function slug(): string
    {
        return 'items';
    }

    public function model(): string
    {
        return Material::class;
    }

    public function title(): string
    {
        return 'Items';
    }

    public function singular(): string
    {
        return 'Item';
    }

    public function numberType(): ?string
    {
        return 'material';
    }

    public function with(): array
    {
        return ['category:id,name', 'unit:id,symbol', 'taxRate:id,name'];
    }

    public function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'maxlength' => 30, 'uppercase' => true],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxlength' => 200],
            ['name' => 'item_type', 'label' => 'Type', 'type' => 'select', 'options' => 'item_types', 'required' => true],
            ['name' => 'material_category_id', 'label' => 'Category', 'type' => 'select', 'options' => 'categories'],
            ['name' => 'unit_id', 'label' => 'Unit', 'type' => 'select', 'options' => 'units', 'required' => true],
            ['name' => 'tax_rate_id', 'label' => 'GST rate', 'type' => 'select', 'options' => 'tax_rates'],
            ['name' => 'hsn_sac', 'label' => 'HSN / SAC', 'type' => 'text', 'maxlength' => 8],
            ['name' => 'standard_rate', 'label' => 'Standard rate (₹)', 'type' => 'rate'],
            ['name' => 'reorder_level', 'label' => 'Reorder level', 'type' => 'qty'],
            ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'span' => 'full'],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch'],
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'code' => $this->codeRules('materials', $record),
            'name' => ['required', 'string', 'max:200'],
            'item_type' => ['required', Rule::enum(ItemType::class)],
            'material_category_id' => ['nullable', new ExistsInCompany(MaterialCategory::class)],
            'unit_id' => ['required', new ExistsInCompany(Unit::class)],
            'tax_rate_id' => ['nullable', new ExistsInCompany(TaxRate::class)],
            'hsn_sac' => ['nullable', 'string', 'regex:'.IndianFormats::HSN_SAC],
            'standard_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'],
            'reorder_level' => ['nullable', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ];
    }

    public function prepare(array $data, ?Model $record): array
    {
        $data['standard_rate'] = $data['standard_rate'] ?? '0';
        $data['reorder_level'] = $data['reorder_level'] ?? '0';

        return $data;
    }

    public function columns(): array
    {
        return [
            ['key' => 'code', 'label' => 'Code', 'type' => 'code'],
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'category_name', 'label' => 'Category', 'mobile' => false],
            ['key' => 'unit_symbol', 'label' => 'Unit'],
            ['key' => 'tax_rate_name', 'label' => 'GST', 'mobile' => false],
            ['key' => 'standard_rate', 'label' => 'Std. rate', 'type' => 'rate'],
            ['key' => 'is_active', 'label' => 'Status', 'type' => 'status'],
        ];
    }

    public function row(Model $record): array
    {
        /** @var Material $record */
        return parent::row($record) + [
            'category_name' => $record->category?->name,
            'unit_symbol' => $record->unit?->symbol,
            'tax_rate_name' => $record->taxRate?->name,
        ];
    }

    public function options(Request $request): array
    {
        return [
            'item_types' => ItemType::options(),
            'categories' => $this->toOptions(MaterialCategory::query()->active()->orderBy('name')->get(['id', 'name'])),
            'units' => $this->toOptions(Unit::query()->active()->orderBy('name')->get(['id', 'name', 'symbol'])
                ->each(fn ($u) => $u->setAttribute('name', "{$u->name} ({$u->symbol})"))),
            'tax_rates' => $this->toOptions(TaxRate::query()->active()->orderBy('rate')->get(['id', 'name', 'rate'])),
        ];
    }
}
