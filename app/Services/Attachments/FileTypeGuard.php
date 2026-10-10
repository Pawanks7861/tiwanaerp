<?php

namespace App\Services\Attachments;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Known business types are checked by detected MIME and, where it is reliable, by signature.
 * Any other extension is allowed unless it is on the executable block-list or the name is unsafe.
 * The client-supplied MIME header is never trusted.
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

    /**
     * @return array<string, mixed>
     */
    public static function fileRules(string $fileKey = 'file'): array
    {
        $maxKb = (int) config('uploads.max_kb');

        return [
            $fileKey => ['nullable', 'required_without:upload_id', 'file', "max:{$maxKb}", function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value instanceof UploadedFile && ! app(self::class)->isAllowed($value)) {
                    $fail('File type not permitted for security reasons');
                }
            }],
            'upload_id' => ['nullable', 'required_without:'.$fileKey, 'uuid'],
        ];
    }

    public function isAllowed(UploadedFile $file): bool
    {
        $name = $file->getClientOriginalName();
        if (! $this->safeName($name) || $this->blocked($name)) {
            return false;
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $known = config('uploads.allowed');
        if (isset($known[$extension])) {
            return $this->accepts($file, $known);
        }

        return true;
    }

    public function originalName(string $name): string
    {
        $name = trim($name);
        if (! $this->safeName($name) || $this->blocked($name)) {
            throw ValidationException::withMessages([
                'file' => 'File type not permitted for security reasons',
            ]);
        }

        return $name;
    }

    public function extensionOf(string $name): string
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }

    public function safeName(string $name): bool
    {
        if ($name === '' || strlen($name) > 200 || str_contains($name, "\0") || str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '\\')) {
            return false;
        }

        return basename($name) === $name;
    }

    public function blocked(string $filename): bool
    {
        $filename = strtolower(basename(str_replace('\\', '/', $filename)));
        if (in_array($filename, ['.env', '.htaccess', '.user.ini', 'web.config'], true)) {
            return true;
        }

        $parts = explode('.', $filename);
        if (count($parts) < 2) {
            return true;
        }

        array_shift($parts);
        $blocked = config('uploads.blocked_extensions');
        foreach ($parts as $part) {
            if ($part === '' || in_array($part, $blocked, true) || preg_match('/^[a-z0-9]{1,16}$/', $part) !== 1) {
                return true;
            }
        }

        return false;
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
