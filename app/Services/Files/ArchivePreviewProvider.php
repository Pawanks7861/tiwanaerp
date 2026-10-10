<?php

namespace App\Services\Files;

use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Lists ZIP entry names only. Nothing is extracted, and RAR/7Z are left as download-only.
 */
class ArchivePreviewProvider
{
    /**
     * @return array{entries: list<string>, truncated: bool, message: ?string}
     */
    public function list(PreviewableFile $file): array
    {
        if ($file->extension !== 'zip') {
            return ['entries' => [], 'truncated' => false, 'message' => 'Preview not available'];
        }

        $cap = max(1, (int) config('uploads.preview_archive_entries'));
        if ($file->size > (int) config('uploads.preview_sheet_max_bytes')) {
            return ['entries' => [], 'truncated' => true, 'message' => 'Preview limited for performance.'];
        }

        $archive = new ZipArchive;
        $opened = $archive->open(Storage::disk($file->disk)->path($file->path));
        if ($opened !== true) {
            return ['entries' => [], 'truncated' => false, 'message' => PreviewConversionException::CORRUPTED];
        }

        $entries = [];
        $total = $archive->numFiles;
        $limit = min($total, $cap);
        for ($i = 0; $i < $limit; $i++) {
            $name = $archive->getNameIndex($i);
            if (is_string($name) && $name !== '') {
                $entries[] = $name;
            }
        }
        $archive->close();

        $truncated = $total > $cap;

        return [
            'entries' => $entries,
            'truncated' => $truncated,
            'message' => $truncated ? 'Preview limited for performance.' : null,
        ];
    }
}
