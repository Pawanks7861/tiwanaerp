<?php

namespace App\Exports\Imports;

use App\Exports\Imports\Sheets\ImportDataSheet;
use App\Exports\Imports\Sheets\ImportInstructionsSheet;
use App\Exports\Imports\Sheets\ImportReferenceSheet;
use App\Models\Projects\Project;
use App\Models\User;
use App\Support\Imports\ImportDefinition;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Multi-sheet import workbook generated from an ImportDefinition (single source of truth).
 * Sheet 1 is always Import Data so uploads read the correct columns.
 */
class ImportTemplateExport implements Export, WithMultipleSheets
{
    use Exportable;

    /**
     * @param  list<array{title: string, headings: list<string>, rows: list<list<mixed>>, note?: string}>  $referenceSheets
     */
    public function __construct(
        private readonly ImportDefinition $definition,
        private readonly array $referenceSheets = [],
    ) {}

    public static function for(ImportDefinition $definition, User $user, ?Project $project = null): self
    {
        $refs = array_slice($definition->referenceData($user, $project), 0, 8);

        return new self($definition, $refs);
    }

    /**
     * Data-only export for CSV (CSV cannot carry Instructions / Reference sheets).
     */
    public function dataSheetOnly(): ImportDataSheet
    {
        return new ImportDataSheet($this->definition);
    }

    public function sheets(): array
    {
        $sheets = [
            new ImportDataSheet($this->definition),
            new ImportInstructionsSheet($this->definition),
        ];

        foreach ($this->referenceSheets as $index => $ref) {
            if (($ref['rows'] ?? []) === [] && ($ref['headings'] ?? []) === []) {
                continue;
            }
            $sheets[] = new ImportReferenceSheet($ref, $index);
        }

        return $sheets;
    }
}
