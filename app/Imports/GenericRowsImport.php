<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Reads the first sheet as heading-keyed rows. Persistence stays in the import service.
 */
class GenericRowsImport implements ToArray, WithCalculatedFormulas, WithHeadingRow
{
    /**
     * @param  array<int, array<string, mixed>>  $array
     */
    public function array(array $array): void {}
}
