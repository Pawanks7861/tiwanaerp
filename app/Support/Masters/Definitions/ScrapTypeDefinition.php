<?php

namespace App\Support\Masters\Definitions;

use App\Models\Masters\ScrapCategory;
use App\Models\Masters\ScrapType;
use App\Models\Masters\Unit;
use App\Support\Masters\MasterDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ScrapTypeDefinition extends MasterDefinition
{
    public function slug(): string
    {
        return 'scrap-types';
    }

    public function model(): string
    {
        return ScrapType::class;
    }

    public function title(): string
    {
        return 'Scrap Types';
    }

    public function singular(): string
    {
        return 'Scrap Type';
    }

    public function permission(): string
    {
        return 'masters.scrap_types';
    }

    public function with(): array
    {
        return ['category:id,name,code', 'unit:id,symbol,name'];
    }

    public function fields(): array
    {
        return [
            ['name' => 'scrap_category_id', 'label' => 'Category', 'type' => 'select', 'options' => 'categories', 'required' => true],
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'maxlength' => 30, 'uppercase' => true],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxlength' => 150],
            ['name' => 'unit_id', 'label' => 'Default unit', 'type' => 'select', 'options' => 'units'],
            ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'maxlength' => 500, 'span' => 'full'],
            ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'default' => 0],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch'],
        ];
    }

    public function rules(?Model $record): array
    {
        $categoryId = request()->input('scrap_category_id', $record?->scrap_category_id);

        return [
            'scrap_category_id' => ['required', 'integer', Rule::exists('scrap_categories', 'id')->where('company_id', app(\App\Support\Tenancy\CurrentCompany::class)->id())],
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('scrap_types', 'code')
                    ->where('company_id', app(\App\Support\Tenancy\CurrentCompany::class)->id())
                    ->where('scrap_category_id', $categoryId)
                    ->ignore($record?->id),
            ],
            'name' => ['required', 'string', 'max:150'],
            'unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    public function columns(): array
    {
        return [
            ['key' => 'code', 'label' => 'Code'],
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'category.name', 'label' => 'Category'],
            ['key' => 'unit.symbol', 'label' => 'Unit'],
            ['key' => 'is_active', 'label' => 'Status', 'type' => 'status'],
        ];
    }

    public function options(Request $request): array
    {
        return [
            'categories' => ScrapCategory::query()->active()->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(fn ($c) => ['value' => $c->id, 'label' => "{$c->code} — {$c->name}"])
                ->all(),
            'units' => Unit::query()->orderBy('name')
                ->get(['id', 'symbol', 'name'])
                ->map(fn ($u) => ['value' => $u->id, 'label' => "{$u->symbol} — {$u->name}"])
                ->all(),
        ];
    }
}
