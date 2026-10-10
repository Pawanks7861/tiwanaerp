<?php

namespace App\Services\Files;

use Illuminate\Support\Facades\Storage;

/**
 * Reads only a capped prefix of a text file. The caller returns it as JSON, never as HTML.
 */
class TextPreviewProvider
{
    /**
     * @return array{text: string, truncated: bool}
     */
    public function read(PreviewableFile $file): array
    {
        $cap = max(1024, (int) config('uploads.preview_text_bytes'));
        $absolute = Storage::disk($file->disk)->path($file->path);
        $handle = fopen($absolute, 'rb');
        if ($handle === false) {
            return ['text' => '', 'truncated' => false];
        }

        $chunk = fread($handle, $cap + 1);
        fclose($handle);
        $chunk = is_string($chunk) ? $chunk : '';
        $truncated = strlen($chunk) > $cap;

        return [
            'text' => substr($chunk, 0, $cap),
            'truncated' => $truncated,
        ];
    }

    /**
     * @return array{text: string, rows: list<list<string>>, truncated: bool}
     */
    public function csv(PreviewableFile $file): array
    {
        $text = $this->read($file);
        $rowCap = max(1, (int) config('uploads.preview_csv_rows'));
        $columnCap = max(1, (int) config('uploads.preview_sheet_columns'));
        $rows = [];
        $lineCount = 0;

        foreach (preg_split("/\r\n|\n|\r/", $text['text']) ?: [] as $line) {
            if ($line === '' && $rows === []) {
                continue;
            }
            $lineCount++;
            if (count($rows) >= $rowCap) {
                continue;
            }
            $cells = str_getcsv($line);
            $rows[] = array_map(fn ($cell) => (string) $cell, array_slice($cells, 0, $columnCap));
        }

        return [
            'text' => $text['text'],
            'rows' => $rows,
            'truncated' => $text['truncated'] || $lineCount > $rowCap,
        ];
    }
}
