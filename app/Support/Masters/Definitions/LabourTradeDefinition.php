<?php

namespace App\Support\Masters\Definitions;

use App\Models\Masters\LabourTrade;
use App\Support\Masters\MasterDefinition;
use Illuminate\Database\Eloquent\Model;

class LabourTradeDefinition extends MasterDefinition
{
    public function slug(): string
    {
        return 'labour-trades';
    }

    public function model(): string
    {
        return LabourTrade::class;
    }

    public function title(): string
    {
        return 'Labour Trades';
    }

    public function singular(): string
    {
        return 'Labour Trade';
    }

    public function fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Trade', 'type' => 'text', 'required' => true, 'maxlength' => 100, 'placeholder' => 'Mason'],
            ['name' => 'default_daily_wage', 'label' => 'Default daily wage (₹)', 'type' => 'money'],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch'],
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:100', $this->unique('labour_trades', 'name', $record)],
            'default_daily_wage' => ['nullable', 'numeric', 'min:0', 'max:9999999', 'decimal:0,2'],
            'is_active' => ['boolean'],
        ];
    }

    public function prepare(array $data, ?Model $record): array
    {
        $data['default_daily_wage'] = $data['default_daily_wage'] ?? '0';

        return $data;
    }

    public function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Trade'],
            ['key' => 'default_daily_wage', 'label' => 'Daily wage', 'type' => 'money'],
            ['key' => 'is_active', 'label' => 'Status', 'type' => 'status'],
        ];
    }
}
