<?php

namespace App\Support\Masters\Definitions;

use App\Models\Masters\ScrapCategory;
use App\Support\Masters\MasterDefinition;
use Illuminate\Database\Eloquent\Model;

class ScrapCategoryDefinition extends MasterDefinition
{
    public function slug(): string
    {
        return 'scrap-categories';
    }

    public function model(): string
    {
        return ScrapCategory::class;
    }

    public function title(): string
    {
        return 'Scrap Categories';
    }

    public function singular(): string
    {
        return 'Scrap Category';
    }

    public function permission(): string
    {
        return 'masters.scrap_categories';
    }

    public function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'maxlength' => 30, 'uppercase' => true],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxlength' => 150],
            ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'maxlength' => 500, 'span' => 'full'],
            ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'default' => 0],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch'],
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'max:30', $this->unique('scrap_categories', 'code', $record)],
            'name' => ['required', 'string', 'max:150'],
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
            ['key' => 'sort_order', 'label' => 'Order'],
            ['key' => 'is_active', 'label' => 'Status', 'type' => 'status'],
        ];
    }
}
