<?php

namespace App\Services\Files;

use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use Throwable;

/**
 * Read-only sheet preview. Formulas are returned as text. The workbook is not calculated
 * and files above the preview cap are not opened.
 */
class SpreadsheetPreviewProvider
{
    /**
     * @return array{sheets: list<array{name: string, rows: list<list<string>>}>, truncated: bool, message: ?string}
     */
    public function read(PreviewableFile $file): array
    {
        $maxBytes = max(1, (int) config('uploads.preview_sheet_max_bytes'));
        if ($file->size > $maxBytes) {
            return ['sheets' => [], 'truncated' => true, 'message' => 'Preview limited for performance.'];
        }

        $absolute = Storage::disk($file->disk)->path($file->path);
        $rowCap = max(1, (int) config('uploads.preview_sheet_rows'));
        $columnCap = max(1, (int) config('uploads.preview_sheet_columns'));

        try {
            $type = $file->extension === 'xls' ? 'Xls' : 'Xlsx';
            $reader = IOFactory::createReader($type);
            $reader->setReadDataOnly(true);
            $info = $reader->listWorksheetInfo($absolute);
            $truncated = false;
            foreach (array_slice($info, 0, 9) as $sheetInfo) {
                if (($sheetInfo['totalRows'] ?? 0) > $rowCap || ($sheetInfo['totalColumns'] ?? 0) > $columnCap) {
                    $truncated = true;
                }
            }
            if (count($info) > 8) {
                $truncated = true;
            }
            $reader->setReadFilter(new class($rowCap, $columnCap) implements IReadFilter
            {
                public function __construct(private int $rows, private int $columns) {}

                public function readCell(string $column, int $row, string $worksheetName = ''): bool
                {
                    return $row <= $this->rows && Coordinate::columnIndexFromString($column) <= $this->columns;
                }
            });
            $book = $reader->load($absolute);
        } catch (Throwable) {
            return ['sheets' => [], 'truncated' => false, 'message' => PreviewConversionException::CORRUPTED];
        }

        $sheets = [];
        $index = 0;
        foreach ($book->getWorksheetIterator() as $sheet) {
            $index++;
            if ($index > 8) {
                break;
            }
            $rows = [];
            $highest = min($rowCap, max(1, (int) $sheet->getHighestDataRow()));
            foreach ($sheet->getRowIterator(1, $highest) as $row) {
                $cells = [];
                foreach ($row->getCellIterator() as $cell) {
                    if (count($cells) >= $columnCap) {
                        $truncated = true;
                        break;
                    }
                    $cells[] = $this->text($cell->getValue());
                }
                $rows[] = $cells;
            }
            $sheets[] = ['name' => $sheet->getTitle(), 'rows' => $rows];
        }
        $book->disconnectWorksheets();

        return ['sheets' => $sheets, 'truncated' => $truncated, 'message' => $truncated ? 'Preview limited for performance.' : null];
    }

    private function text(mixed $value): string
    {
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }
}
