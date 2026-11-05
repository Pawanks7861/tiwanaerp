<?php

namespace App\Services\Imports;

use App\Imports\GenericRowsImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class SpreadsheetReader
{
    /**
     * @return list<string>
     */
    public function sheetNames(string $disk, string $path): array
    {
        $absolute = Storage::disk($disk)->path($path);

        try {
            $reader = IOFactory::createReaderForFile($absolute);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($absolute);
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages([
                'file' => 'The file could not be read. Upload an .xlsx, .xls or .csv file based on the module template.',
            ]);
        }

        return array_map(
            fn ($sheet) => (string) $sheet->getTitle(),
            $spreadsheet->getAllSheets(),
        );
    }

    /**
     * @return array{headers: list<string>, rows: list<array<string, mixed>>, raw_headers: list<string>}
     */
    public function read(string $disk, string $path, int $sheetIndex = 0): array
    {
        $absolute = Storage::disk($disk)->path($path);

        try {
            $sheets = Excel::toArray(new GenericRowsImport, $absolute);
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages([
                'file' => 'The file could not be read. Upload an .xlsx, .xls or .csv file based on the module template.',
            ]);
        }

        if ($sheets === []) {
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }

        if ($sheetIndex < 0 || $sheetIndex >= count($sheets)) {
            throw ValidationException::withMessages(['sheet_index' => 'Choose a valid worksheet.']);
        }

        $sheet = $sheets[$sheetIndex] ?? [];

        if ($sheet === []) {
            throw ValidationException::withMessages(['file' => 'The selected worksheet is empty.']);
        }

        // WithHeadingRow already keyed rows. Rebuild display headers from first row keys.
        $first = $sheet[array_key_first($sheet)] ?? [];
        $headers = array_map(fn ($key) => (string) $key, array_keys($first));

        $max = (int) config('imports.max_rows', 5000);
        if (count($sheet) > $max) {
            throw ValidationException::withMessages([
                'file' => "The file has more than {$max} data rows. Split it into smaller files.",
            ]);
        }

        return [
            'headers' => $headers,
            'raw_headers' => $headers,
            'rows' => array_values($sheet),
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
}
