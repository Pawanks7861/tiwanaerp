<?php

namespace App\Support\Masters\Definitions;

use App\Models\Masters\Subcontractor;
use Illuminate\Database\Eloquent\Model;

class SubcontractorDefinition extends VendorDefinition
{
    public function slug(): string
    {
        return 'subcontractors';
    }

    public function model(): string
    {
        return Subcontractor::class;
    }

    public function title(): string
    {
        return 'Subcontractors';
    }

    public function singular(): string
    {
        return 'Subcontractor';
    }

    public function numberType(): ?string
    {
        return 'subcontractor';
    }

    protected function table(): string
    {
        return 'subcontractors';
    }

    protected function identityFields(): array
    {
        return [
            ...parent::identityFields(),
            ['name' => 'trade', 'label' => 'Trade / scope', 'type' => 'text', 'maxlength' => 100, 'section' => 'General', 'placeholder' => 'Shuttering, Plumbing, ...'],
        ];
    }

    public function rules(?Model $record): array
    {
        return parent::rules($record) + ['trade' => ['nullable', 'string', 'max:100']];
    }

    public function columns(): array
    {
        $columns = parent::columns();
        array_splice($columns, 2, 0, [['key' => 'trade', 'label' => 'Trade', 'mobile' => false]]);

        return $columns;
    }
}
