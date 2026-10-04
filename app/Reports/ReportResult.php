<?php

namespace App\Reports;

/**
 * Report output shared by the screen and both exports. Columns, cards and charts carry a
 * sensitivity ('financial' or 'valuation'); restrict() removes what the user may not see so the
 * values never leave the server, and rows keep only column keys plus link metadata.
 */
final class ReportResult
{
    private const ROW_META = ['url', 'flag', 'emphasis'];

    /**
     * @param  list<array{key: string, label: string, type?: string, align?: string, sensitive?: string, mobile?: bool}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $totals  keyed by column
     * @param  list<array{key: string, label: string, value: mixed, type?: string, sensitive?: string, tone?: string, hint?: string}>  $cards
     * @param  list<array<string, mixed>>  $charts  [type, title, categories, series, money?, sensitive?]
     * @param  list<string>  $notes
     * @param  array<string, mixed>|null  $pagination  Laravel paginator array without data
     * @param  list<array{title: string, columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, totals?: array<string, mixed>|null}>  $sections
     */
    public function __construct(
        public array $columns = [],
        public array $rows = [],
        public ?array $totals = null,
        public array $cards = [],
        public array $charts = [],
        public array $notes = [],
        public ?array $pagination = null,
        public array $sections = [],
    ) {}

    public function restrict(bool $financial, bool $valuation): self
    {
        $allowed = fn (array $item) => match ($item['sensitive'] ?? null) {
            'financial' => $financial,
            'valuation' => $valuation,
            default => true,
        };

        [$this->columns, $this->rows, $this->totals] = $this->restrictTable($this->columns, $this->rows, $this->totals, $allowed);
        $this->cards = array_values(array_filter($this->cards, $allowed));
        $this->charts = array_values(array_filter($this->charts, $allowed));
        $this->sections = array_values(array_map(function (array $section) use ($allowed) {
            [$section['columns'], $section['rows'], $section['totals']] = $this->restrictTable($section['columns'], $section['rows'], $section['totals'] ?? null, $allowed);

            return $section;
        }, array_filter($this->sections, $allowed)));

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'columns' => $this->columns,
            'rows' => $this->rows,
            'totals' => $this->totals,
            'cards' => $this->cards,
            'charts' => $this->charts,
            'notes' => $this->notes,
            'pagination' => $this->pagination,
            'sections' => $this->sections,
        ];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: array<string, mixed>|null}
     */
    private function restrictTable(array $columns, array $rows, ?array $totals, callable $allowed): array
    {
        $columns = array_values(array_filter($columns, $allowed));
        $keys = array_flip(array_merge(array_column($columns, 'key'), self::ROW_META));
        $rows = array_map(fn (array $row) => array_intersect_key($row, $keys), $rows);
        $totals = $totals === null ? null : array_intersect_key($totals, $keys);

        return [$columns, $rows, $totals];
    }
}
