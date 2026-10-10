<?php

namespace App\Exports;

use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqSection;
use App\Support\Exports\SpreadsheetText;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * BOQ export. Internal cost columns are only included for users allowed to see costs.
 */
class BoqExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(private readonly Boq $boq, private readonly bool $withCosts) {}

    public function title(): string
    {
        return substr("{$this->boq->boq_number} v{$this->boq->version}", 0, 31);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        $costs = $this->withCosts
            ? ['Material Rate', 'Labour Rate', 'Equipment Rate', 'Subcontract Rate', 'Cost Rate', 'Cost Amount', 'Margin %']
            : [];

        return ['Section', 'Code', 'Name', 'Description', 'HSN/SAC', 'Unit', 'Quantity', ...$costs, 'Client Rate', 'Client Amount'];
    }

    /**
     * @return list<list<string|null>>
     */
    public function array(): array
    {
        $sections = $this->boq->sections()->get()->keyBy('id');
        $items = $this->boq->items()->with('unit:id,symbol')->orderBy('sort_order')->orderBy('id')->get();

        $rows = [];
        foreach ($this->orderedSectionIds($sections) as $sectionId) {
            foreach ($items->where('boq_section_id', $sectionId) as $item) {
                $rows[] = $this->row($item, $this->sectionLabel($sections, $sections[$sectionId]));
            }
        }

        $totals = array_fill(0, count($this->headings()), null);
        $totals[2] = 'Total';
        if ($this->withCosts) {
            $totals[12] = $this->boq->total_cost_amount;
        }
        $totals[count($totals) - 1] = $this->boq->total_client_amount;
        $rows[] = $totals;

        return $rows;
    }

    /**
     * @return list<string|null>
     */
    private function row(BoqItem $item, string $section): array
    {
        $costs = $this->withCosts
            ? [$item->material_rate, $item->labour_rate, $item->equipment_rate, $item->subcontract_rate, $item->cost_rate, $item->cost_amount, $item->margin_percent]
            : [];

        return [
            SpreadsheetText::cell($section),
            SpreadsheetText::cell($item->item_code),
            SpreadsheetText::cell($item->name),
            SpreadsheetText::cell($item->description),
            SpreadsheetText::cell($item->hsn_sac),
            $item->unit?->symbol,
            $item->quantity, ...$costs, $item->client_rate, $item->client_amount,
        ];
    }

    /**
     * @param  Collection<int, BoqSection>  $sections
     * @return list<int>
     */
    private function orderedSectionIds($sections): array
    {
        $ids = [];
        foreach ($sections->whereNull('parent_id')->sortBy([['sort_order', 'asc'], ['id', 'asc']]) as $top) {
            $ids[] = $top->id;
            foreach ($sections->where('parent_id', $top->id)->sortBy([['sort_order', 'asc'], ['id', 'asc']]) as $child) {
                $ids[] = $child->id;
            }
        }

        return $ids;
    }

    /**
     * @param  Collection<int, BoqSection>  $sections
     */
    private function sectionLabel($sections, BoqSection $section): string
    {
        $label = trim(($section->code ? $section->code.' ' : '').$section->name);
        if ($section->parent_id !== null && $sections->has($section->parent_id)) {
            return $this->sectionLabel($sections, $sections[$section->parent_id]).' / '.$label;
        }

        return $label;
    }
}
