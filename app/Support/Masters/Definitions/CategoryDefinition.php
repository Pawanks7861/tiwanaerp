<?php

namespace App\Support\Masters\Definitions;

use App\Models\Masters\MaterialCategory;
use App\Rules\ExistsInCompany;
use App\Support\Masters\MasterDefinition;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class CategoryDefinition extends MasterDefinition
{
    public function slug(): string
    {
        return 'categories';
    }

    public function model(): string
    {
        return MaterialCategory::class;
    }

    public function title(): string
    {
        return 'Material Categories';
    }

    public function singular(): string
    {
        return 'Category';
    }

    public function with(): array
    {
        return ['parent:id,name'];
    }

    public function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'maxlength' => 30, 'uppercase' => true],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxlength' => 100],
            ['name' => 'parent_id', 'label' => 'Parent category', 'type' => 'select', 'options' => 'parents'],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch'],
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'code' => $this->codeRules('material_categories', $record),
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', new ExistsInCompany(MaterialCategory::class), $this->noCycle($record)],
            'is_active' => ['boolean'],
        ];
    }

    public function columns(): array
    {
        return [
            ['key' => 'code', 'label' => 'Code', 'type' => 'code'],
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'parent_name', 'label' => 'Parent', 'mobile' => false],
            ['key' => 'is_active', 'label' => 'Status', 'type' => 'status'],
        ];
    }

    public function row(Model $record): array
    {
        /** @var MaterialCategory $record */
        return parent::row($record) + ['parent_name' => $record->parent?->name];
    }

    public function options(Request $request): array
    {
        return [
            'parents' => $this->toOptions(MaterialCategory::query()->active()->orderBy('name')->get(['id', 'name'])),
        ];
    }

    /**
     * A category cannot be its own parent or sit under one of its descendants.
     */
    private function noCycle(?Model $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record) {
            if ($record === null || $value === null) {
                return;
            }

            $cursor = (int) $value;
            $guard = 0;
            while ($cursor && $guard++ < 50) {
                if ($cursor === (int) $record->getKey()) {
                    $fail('A category cannot be placed under itself or one of its sub-categories.');

                    return;
                }
                $cursor = (int) MaterialCategory::query()->whereKey($cursor)->value('parent_id');
            }
        };
    }
}
