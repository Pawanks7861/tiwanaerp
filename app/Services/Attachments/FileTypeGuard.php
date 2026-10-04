<?php

namespace App\Services\Attachments;

use Illuminate\Http\UploadedFile;

/**
 * Validates uploads by extension allow-list, server-detected MIME type and, for CAD files,
 * file signature. The client-supplied MIME header is never trusted.
 */
class FileTypeGuard
{
    /**
     * @return list<string>
     */
    public static function extensions(): array
    {
        return array_keys(config('uploads.allowed'));
    }

    public function isAllowed(UploadedFile $file): bool
    {
        return $this->accepts($file, config('uploads.allowed'));
    }

    /**
     * @param  array<string, list<string>>  $allowed  extension => detected MIME types
     */
    public function accepts(UploadedFile $file, array $allowed): bool
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! isset($allowed[$extension])) {
            return false;
        }

        $detectedMime = (string) $file->getMimeType();
        if (! in_array($detectedMime, $allowed[$extension], true)) {
            return false;
        }

        return match ($extension) {
            'png' => str_starts_with($this->head($file, 8), "\x89PNG\r\n\x1a\n"),
            'jpg', 'jpeg' => str_starts_with($this->head($file, 3), "\xFF\xD8\xFF"),
            'webp' => str_starts_with($this->head($file, 4), 'RIFF') && substr($this->head($file, 12), 8, 4) === 'WEBP',
            'dwg' => $this->hasDwgSignature($file),
            'dxf' => $this->looksLikeDxf($file),
            default => true,
        };
    }

    public function isImage(UploadedFile $file): bool
    {
        return in_array(strtolower($file->getClientOriginalExtension()), config('uploads.image_extensions'), true);
    }

    private function hasDwgSignature(UploadedFile $file): bool
    {
        // AutoCAD DWG files start with "AC10" followed by the version digits (e.g. AC1032).
        return str_starts_with($this->head($file, 6), 'AC10');
    }

    private function looksLikeDxf(UploadedFile $file): bool
    {
        $head = $this->head($file, 64);

        return str_starts_with($head, 'AutoCAD Binary DXF')
            || preg_match('/^\s*0\s*\r?\n\s*SECTION/', $head) === 1
            || preg_match('/^\s*999\s*\r?\n/', $head) === 1;
    }

    private function head(UploadedFile $file, int $bytes): string
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            return '';
        }

        try {
            return (string) fread($handle, $bytes);
        } finally {
            fclose($handle);
        }
    }
}
