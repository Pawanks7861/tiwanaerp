<?php

namespace App\Support\Masters\Definitions;

use App\Models\Masters\EquipmentType;
use App\Support\Masters\MasterDefinition;
use Illuminate\Database\Eloquent\Model;

class EquipmentTypeDefinition extends MasterDefinition
{
    public function slug(): string
    {
        return 'equipment-types';
    }

    public function model(): string
    {
        return EquipmentType::class;
    }

    public function title(): string
    {
        return 'Equipment Types';
    }

    public function singular(): string
    {
        return 'Equipment Type';
    }

    public function fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxlength' => 100, 'placeholder' => 'Concrete Mixer'],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch'],
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:100', $this->unique('equipment_types', 'name', $record)],
            'is_active' => ['boolean'],
        ];
    }

    public function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'is_active', 'label' => 'Status', 'type' => 'status'],
        ];
    }
}
