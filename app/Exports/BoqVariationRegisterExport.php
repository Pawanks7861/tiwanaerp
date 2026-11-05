<?php

namespace App\Exports;

use App\Models\Boq\BoqVariation;
use App\Models\Projects\Project;
use App\Support\Exports\SpreadsheetText;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * @implements WithMapping<BoqVariation>
 */
class BoqVariationRegisterExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  Collection<int, BoqVariation>  $rows
     */
    public function __construct(
        private readonly Project $project,
        private readonly Collection $rows,
        private readonly bool $includeFinancials = false,
    ) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        $heads = [
            'Project', 'Variation No.', 'Title', 'Type', 'Source', 'Status',
            'Submitted By', 'Approved By', 'Approved At',
        ];
        if ($this->includeFinancials) {
            $heads = array_merge($heads, ['Revenue Impact', 'Cost Impact', 'Margin Impact']);
        }

        return $heads;
    }

    /**
     * @param  BoqVariation  $row
     * @return list<string>
     */
    public function map($row): array
    {
        $out = [
            SpreadsheetText::cell($this->project->code.' — '.$this->project->name),
            SpreadsheetText::cell($row->variation_number),
            SpreadsheetText::cell($row->title),
            SpreadsheetText::cell($row->change_order_type->label()),
            SpreadsheetText::cell($row->source_type->label()),
            SpreadsheetText::cell($row->status->label()),
            SpreadsheetText::cell((string) ($row->submittedBy?->name ?? '')),
            SpreadsheetText::cell((string) ($row->approvedBy?->name ?? '')),
            SpreadsheetText::cell((string) ($row->approved_at?->toDateTimeString() ?? '')),
        ];
        if ($this->includeFinancials) {
            $out[] = SpreadsheetText::cell((string) $row->revenue_impact);
            $out[] = SpreadsheetText::cell((string) $row->cost_impact);
            $out[] = SpreadsheetText::cell((string) $row->margin_impact);
        }

        return $out;
    }
}
