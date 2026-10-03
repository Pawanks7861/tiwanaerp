<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Reads the first sheet of a BOQ upload as heading-keyed rows. Validation and persistence live in
 * BoqImportService so the whole file is checked before anything is written.
 */
class BoqRowsImport implements ToArray, WithCalculatedFormulas, WithHeadingRow
{
    /**
     * @param  array<int, array<string, mixed>>  $array
     */
    public function array(array $array): void {}
}
