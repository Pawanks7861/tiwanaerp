<?php

namespace App\Support\Masters\Definitions;

use App\Models\Masters\Department;
use App\Support\Masters\MasterDefinition;
use Illuminate\Database\Eloquent\Model;

class DepartmentDefinition extends MasterDefinition
{
    public function slug(): string
    {
        return 'departments';
    }

    public function model(): string
    {
        return Department::class;
    }

    public function title(): string
    {
        return 'Departments';
    }

    public function singular(): string
    {
        return 'Department';
    }

    public function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'maxlength' => 30],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxlength' => 150],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch'],
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'max:30', $this->unique('departments', 'code', $record)],
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['boolean'],
        ];
    }

    public function columns(): array
    {
        return [
            ['key' => 'code', 'label' => 'Code'],
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'is_active', 'label' => 'Status', 'type' => 'status'],
        ];
    }
}
