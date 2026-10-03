<?php

namespace App\Support\Masters\Definitions;

use App\Models\Masters\Unit;
use App\Support\Masters\MasterDefinition;
use Illuminate\Database\Eloquent\Model;

class UnitDefinition extends MasterDefinition
{
    public function slug(): string
    {
        return 'units';
    }

    public function model(): string
    {
        return Unit::class;
    }

    public function title(): string
    {
        return 'Units';
    }

    public function singular(): string
    {
        return 'Unit';
    }

    public function fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxlength' => 50, 'placeholder' => 'Cubic Metre'],
            ['name' => 'symbol', 'label' => 'Symbol', 'type' => 'text', 'required' => true, 'maxlength' => 20, 'placeholder' => 'Cum'],
            ['name' => 'decimal_places', 'label' => 'Decimal places', 'type' => 'number', 'required' => true, 'help' => 'Quantity precision for this unit (0–4).'],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch'],
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'symbol' => ['required', 'string', 'max:20', $this->unique('units', 'symbol', $record)],
            'decimal_places' => ['required', 'integer', 'between:0,4'],
            'is_active' => ['boolean'],
        ];
    }

    public function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'symbol', 'label' => 'Symbol', 'type' => 'code'],
            ['key' => 'decimal_places', 'label' => 'Decimals', 'mobile' => false],
            ['key' => 'is_active', 'label' => 'Status', 'type' => 'status'],
        ];
    }
}
