<?php

namespace App\Services\Imports;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

class SpreadsheetReader
{
    /** @var list<string> */
    private const HEADER_TERMS = [
        'description', 'description of work', 'particular', 'particulars', 'item', 'activity',
        'qty', 'quantity', 'unit', 'uom', 'u.o.m', 'rate', 'unit rate', 'amount', 'total',
        'hsn', 'hsn/sac', 'sac', 'gst', 'gst %', 'tax %', 'head', 'section', 'category',
        'code', 'item code', 'boq code',
    ];

    /** @var list<string> */
    private const LIKELY_BOQ_SHEETS = [
        'boq', 'bill of quantity', 'bill of quantities', 'estimate', 'costing', 'summary',
    ];

    /** @var list<string> */
    private const EXCEL_ERRORS = [
        '#REF!', '#VALUE!', '#DIV/0!', '#N/A', '#NAME?', '#NUM!', '#NULL!', '#GETTING_DATA', '#FORMULA!',
    ];

    /**
     * @return list<string>
     */
    public function sheetNames(string $disk, string $path): array
    {
        $absolute = Storage::disk($disk)->path($path);
        $ext = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));

        if ($ext === 'csv') {
            return ['Sheet1'];
        }

        try {
            $reader = IOFactory::createReaderForFile($absolute);
            $names = $reader->listWorksheetNames($absolute);
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages([
                'file' => 'The file could not be read. Upload an .xlsx, .xls or .csv file based on the module template.',
            ]);
        }

        return array_values(array_map(fn ($name) => (string) $name, $names));
    }

    /**
     * Suggest a worksheet index that looks like a BOQ tab (user still confirms).
     *
     * @param  list<string>  $names
     */
    public function suggestSheetIndex(array $names): ?int
    {
        foreach ($names as $index => $name) {
            $normalized = Str::lower(trim($name));
            foreach (self::LIKELY_BOQ_SHEETS as $candidate) {
                if ($normalized === $candidate || str_contains($normalized, $candidate)) {
                    return (int) $index;
                }
            }
        }

        return null;
    }

    /**
     * @return array{
     *     headers: list<string>,
     *     raw_headers: list<string>,
     *     rows: list<array<string, mixed>>,
     *     header_row: int,
     *     suggested_header_row: int,
     *     sheet_name: string,
     *     source_rows: int
     * }
     */
    public function read(string $disk, string $path, int $sheetIndex = 0, ?int $headerRow = null): array
    {
        $absolute = Storage::disk($disk)->path($path);
        $ext = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
        $sheetNames = $this->sheetNames($disk, $path);

        if ($sheetIndex < 0 || $sheetIndex >= count($sheetNames)) {
            throw ValidationException::withMessages(['sheet_index' => 'Choose a valid worksheet.']);
        }

        $sheetName = $sheetNames[$sheetIndex];

        try {
            $matrix = $ext === 'csv'
                ? $this->loadCsvMatrix($absolute)
                : $this->loadExcelMatrix($absolute, $sheetName);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages([
                'file' => 'The file could not be read. Upload an .xlsx, .xls or .csv file based on the module template.',
            ]);
        }

        if ($matrix === []) {
            throw ValidationException::withMessages(['file' => 'The selected worksheet is empty.']);
        }

        $suggested = $this->detectHeaderRow($matrix);
        $chosen = $headerRow !== null && $headerRow >= 1 ? $headerRow : $suggested;
        if ($chosen < 1 || $chosen > count($matrix)) {
            throw ValidationException::withMessages(['header_row' => 'Choose a valid header row.']);
        }

        $rawHeaders = array_map(
            fn ($value) => trim((string) ($value ?? '')),
            $matrix[$chosen - 1] ?? [],
        );

        if ($this->nonEmptyCount($rawHeaders) < 1) {
            throw ValidationException::withMessages(['header_row' => 'The selected header row has no column titles.']);
        }

        $headers = $this->uniqueSlugHeaders($rawHeaders);
        $max = (int) config('imports.max_rows', 5000);
        $rows = [];

        for ($i = $chosen; $i < count($matrix); $i++) {
            $cells = $matrix[$i] ?? [];
            $row = [];
            $hasValue = false;
            foreach ($headers as $col => $header) {
                $value = $cells[$col] ?? null;
                if ($this->isExcelError($value)) {
                    $row[$header] = is_string($value) ? trim($value) : '#REF!';
                    $hasValue = true;

                    continue;
                }
                if ($value !== null && $value !== '') {
                    $hasValue = true;
                }
                $row[$header] = $value;
            }

            if (! $hasValue) {
                continue;
            }

            $rows[] = $row;
            if (count($rows) > $max) {
                throw ValidationException::withMessages([
                    'file' => "The file has more than {$max} data rows. Split it into smaller files.",
                ]);
            }
        }

        return [
            'headers' => $headers,
            'raw_headers' => $rawHeaders,
            'rows' => $rows,
            'header_row' => $chosen,
            'suggested_header_row' => $suggested,
            'sheet_name' => $sheetName,
            'source_rows' => count($rows),
        ];
    }

    public function storeUpload(UploadedFile $file, int $companyId, string $uuid): array
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: 'xlsx');
        if (! in_array($ext, config('imports.mimes'), true)) {
            throw ValidationException::withMessages([
                'file' => 'Upload an .xlsx, .xls or .csv file.',
            ]);
        }

        $disk = (string) config('imports.disk', 'private');
        $directory = trim((string) config('imports.path', 'imports'), '/')."/{$companyId}/{$uuid}";
        $filename = 'upload.'.$ext;
        $path = $file->storeAs($directory, $filename, $disk);

        return [
            'disk' => $disk,
            'path' => $path,
            'original_filename' => Str::limit($file->getClientOriginalName(), 180, ''),
            'mime' => $file->getMimeType(),
            'size_bytes' => $file->getSize() ?: 0,
        ];
    }

    public function isExcelError(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $text = strtoupper(trim($value));

        return in_array($text, self::EXCEL_ERRORS, true);
    }

    /**
     * @return list<list<mixed>>
     */
    private function loadExcelMatrix(string $absolute, string $sheetName): array
    {
        $reader = IOFactory::createReaderForFile($absolute);
        $reader->setReadDataOnly(true);
        if (method_exists($reader, 'setLoadSheetsOnly')) {
            $reader->setLoadSheetsOnly([$sheetName]);
        }

        $spreadsheet = $reader->load($absolute);
        $sheet = $spreadsheet->getSheetByName($sheetName) ?? $spreadsheet->getActiveSheet();
        $matrix = $this->worksheetToMatrix($sheet);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $matrix;
    }

    /**
     * @return list<list<mixed>>
     */
    private function loadCsvMatrix(string $absolute): array
    {
        $contents = file_get_contents($absolute);
        if ($contents === false) {
            throw ValidationException::withMessages(['file' => 'The CSV file could not be read.']);
        }

        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
            file_put_contents($absolute, $contents);
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw ValidationException::withMessages([
                'file' => 'The CSV encoding is not valid UTF-8. Save the file as UTF-8 and try again.',
            ]);
        }

        $delimiter = $this->detectDelimiter($contents);
        $reader = new CsvReader;
        $reader->setDelimiter($delimiter);
        $reader->setEnclosure('"');
        $reader->setSheetIndex(0);
        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($absolute);
        $matrix = $this->worksheetToMatrix($spreadsheet->getActiveSheet());
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $matrix;
    }

    /**
     * @return list<list<mixed>>
     */
    private function worksheetToMatrix(Worksheet $sheet): array
    {
        $highestRow = (int) $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
        $mergedMap = $this->mergedValueMap($sheet);

        $matrix = [];
        for ($row = 1; $row <= $highestRow; $row++) {
            $line = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $coordinate = Coordinate::stringFromColumnIndex($col).$row;
                if (isset($mergedMap[$coordinate])) {
                    $line[] = $mergedMap[$coordinate];

                    continue;
                }

                $cell = $sheet->getCell($coordinate);
                $value = $cell->getCalculatedValue();

                if ($cell->getDataType() === DataType::TYPE_ERROR || $this->isExcelError($value)) {
                    $line[] = is_string($value) && $value !== '' ? $value : '#REF!';

                    continue;
                }

                if (is_string($value) && str_starts_with(ltrim($value), '=') && ! is_numeric(ltrim($value, " \t="))) {
                    $line[] = '#FORMULA!';

                    continue;
                }

                $line[] = $value;
            }
            $matrix[] = $line;
        }

        return $matrix;
    }

    /**
     * Propagate unambiguous merged-cell values into each covered cell.
     *
     * @return array<string, mixed>
     */
    private function mergedValueMap(Worksheet $sheet): array
    {
        $map = [];
        foreach ($sheet->getMergeCells() as $range) {
            [$start, $end] = Coordinate::rangeBoundaries($range);
            $master = $sheet->getCell(Coordinate::stringFromColumnIndex($start[0]).$start[1])->getCalculatedValue();
            if ($master === null || $master === '') {
                continue;
            }
            for ($row = $start[1]; $row <= $end[1]; $row++) {
                for ($col = $start[0]; $col <= $end[0]; $col++) {
                    $map[Coordinate::stringFromColumnIndex($col).$row] = $master;
                }
            }
        }

        return $map;
    }

    /**
     * @param  list<list<mixed>>  $matrix
     */
    private function detectHeaderRow(array $matrix): int
    {
        $limit = min(count($matrix), 30);
        $bestRow = 1;
        $bestScore = -1;

        for ($i = 0; $i < $limit; $i++) {
            $score = $this->scoreHeaderRow($matrix[$i] ?? []);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRow = $i + 1;
            }
        }

        return $bestRow;
    }

    /**
     * @param  list<mixed>  $cells
     */
    private function scoreHeaderRow(array $cells): int
    {
        $joined = Str::lower(implode(' | ', array_map(fn ($v) => trim((string) ($v ?? '')), $cells)));
        if (trim(str_replace('|', '', $joined)) === '') {
            return -1;
        }

        $score = 0;
        foreach (self::HEADER_TERMS as $term) {
            if (str_contains($joined, $term)) {
                $score += strlen($term) > 8 ? 2 : 1;
            }
        }

        return $score;
    }

    /**
     * @param  list<string>  $rawHeaders
     * @return list<string>
     */
    private function uniqueSlugHeaders(array $rawHeaders): array
    {
        $used = [];
        $headers = [];
        foreach ($rawHeaders as $index => $label) {
            $base = Str::slug($label !== '' ? $label : 'column_'.($index + 1), '_');
            if ($base === '') {
                $base = 'column_'.($index + 1);
            }
            $slug = $base;
            $n = 2;
            while (isset($used[$slug])) {
                $slug = $base.'_'.$n;
                $n++;
            }
            $used[$slug] = true;
            $headers[] = $slug;
        }

        return $headers;
    }

    /**
     * @param  list<mixed>  $cells
     */
    private function nonEmptyCount(array $cells): int
    {
        return count(array_filter($cells, fn ($v) => trim((string) ($v ?? '')) !== ''));
    }

    private function detectDelimiter(string $contents): string
    {
        $sample = substr($contents, 0, 4096);
        $candidates = [',' => 0, ';' => 0, "\t" => 0];
        foreach (array_keys($candidates) as $delimiter) {
            $candidates[$delimiter] = substr_count($sample, $delimiter);
        }
        arsort($candidates);
        $best = array_key_first($candidates);

        return ($candidates[$best] ?? 0) > 0 ? (string) $best : ',';
    }
}
