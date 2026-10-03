<?php

namespace App\Support\Masters\Definitions;

use App\Models\Masters\TaxRate;
use App\Support\Masters\MasterDefinition;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * GST rate. CGST/SGST (half each) and IGST (full) are derived on the server from the total rate.
 */
class TaxRateDefinition extends MasterDefinition
{
    public function slug(): string
    {
        return 'tax-rates';
    }

    public function model(): string
    {
        return TaxRate::class;
    }

    public function title(): string
    {
        return 'Tax Rates';
    }

    public function singular(): string
    {
        return 'Tax Rate';
    }

    public function fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxlength' => 50, 'placeholder' => 'GST 18%'],
            ['name' => 'rate', 'label' => 'GST rate (%)', 'type' => 'percent', 'required' => true, 'help' => 'CGST + SGST (half each) and IGST are calculated automatically.'],
            ['name' => 'cess_rate', 'label' => 'Cess (%)', 'type' => 'percent'],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch'],
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:50', $this->unique('tax_rates', 'name', $record)],
            'rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'cess_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'is_active' => ['boolean'],
        ];
    }

    public function prepare(array $data, ?Model $record): array
    {
        $rate = Decimal::of((string) $data['rate']);
        $half = $rate->dividedBy(2, Decimal::PERCENT_SCALE);

        return array_merge($data, [
            'rate' => $rate->toRate(),
            'cgst_rate' => $half->toRate(),
            'sgst_rate' => $half->toRate(),
            'igst_rate' => $rate->toRate(),
            'cess_rate' => Decimal::of((string) ($data['cess_rate'] ?? '0'))->toRate(),
        ]);
    }

    public function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'rate', 'label' => 'GST', 'type' => 'percent'],
            ['key' => 'cgst_rate', 'label' => 'CGST', 'type' => 'percent', 'mobile' => false],
            ['key' => 'sgst_rate', 'label' => 'SGST', 'type' => 'percent', 'mobile' => false],
            ['key' => 'igst_rate', 'label' => 'IGST', 'type' => 'percent', 'mobile' => false],
            ['key' => 'is_active', 'label' => 'Status', 'type' => 'status'],
        ];
    }

    public function row(Model $record): array
    {
        return parent::row($record) + [
            'cgst_rate' => $record->getAttribute('cgst_rate'),
            'sgst_rate' => $record->getAttribute('sgst_rate'),
            'igst_rate' => $record->getAttribute('igst_rate'),
        ];
    }

    public function applySort(Builder $query): void
    {
        $query->orderBy('rate');
    }
}
