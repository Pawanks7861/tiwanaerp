<?php

namespace App\Services\Attachments;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private storage for controlled files (drawing revisions, document versions). Files are validated
 * by FileTypeGuard, stored under a random name per company / project, and checked against their
 * SHA-256 checksum every time they are served. Stored files are never overwritten or deleted.
 */
class PrivateFileStore
{
    public function __construct(private readonly FileTypeGuard $guard) {}

    /**
     * @param  list<string>|null  $extensions  Narrower allow-list than config('uploads.allowed').
     * @return array{disk: string, file_path: string, file_name: string, mime: string, extension: string, size_bytes: int, checksum: string}
     */
    public function put(UploadedFile $file, int $companyId, int $projectId, string $area, ?array $extensions = null): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (($extensions !== null && ! in_array($extension, $extensions, true)) || ! $this->guard->isAllowed($file)) {
            throw ValidationException::withMessages(['file' => 'This file type is not allowed.']);
        }

        $disk = config('uploads.disk');
        $directory = sprintf('company/%d/project/%d/%s/%s', $companyId, $projectId, $area, now()->format('Y/m'));
        $path = $file->storeAs($directory, Str::uuid()->toString().'.'.$extension, ['disk' => $disk]);
        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be stored.']);
        }

        return [
            'disk' => $disk,
            'file_path' => $path,
            'file_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime' => (string) $file->getMimeType(),
            'extension' => $extension,
            'size_bytes' => (int) $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
        ];
    }

    /**
     * Remove a file whose database row was never written (failed transaction).
     *
     * @param  array{disk: string, file_path: string}  $stored
     */
    public function discard(array $stored): void
    {
        Storage::disk($stored['disk'])->delete($stored['file_path']);
    }

    public static function isPreviewable(string $extension): bool
    {
        return in_array(strtolower($extension), config('uploads.preview_extensions'), true);
    }

    /**
     * Stream a stored file after verifying its checksum. Inline only for previewable types.
     *
     * @param  object{disk: string, file_path: string, file_name: string, mime: string, extension: string, checksum: string}  $file
     */
    public function respond(object $file, bool $inline): StreamedResponse
    {
        $storage = Storage::disk($file->disk);
        abort_unless($storage->exists($file->file_path), 404);

        if (! hash_equals($file->checksum, $this->checksumOf($file->disk, $file->file_path))) {
            Log::warning('Controlled file failed its integrity check.', ['path' => $file->file_path]);
            abort(409, 'The stored file failed its integrity check and cannot be served.');
        }

        $inline = $inline && self::isPreviewable($file->extension);
        $headers = [
            'Content-Type' => $inline ? $file->mime : ($file->mime ?: 'application/octet-stream'),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ];

        return $storage->response($file->file_path, $file->file_name, $headers, $inline ? 'inline' : 'attachment');
    }

    public function checksumOf(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);
        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }
}
