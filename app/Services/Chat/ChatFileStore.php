<?php

namespace App\Services\Chat;

use App\Services\Attachments\FileTypeGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private chat files. The allow-list is local so a text file or drawing here does not widen
 * every other upload in the ERP. Executables and scripts are not in the list.
 */
class ChatFileStore
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const MAX_FILES = 5;

    public const MAX_TOTAL_BYTES = 6 * 1024 * 1024;

    public function __construct(private readonly FileTypeGuard $guard) {}

    /**
     * @return array{disk: string, path: string, original_name: string, stored_name: string, mime: string, size_bytes: int, checksum: string}
     */
    public function put(UploadedFile $file, int $companyId): array
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['files' => 'A file is larger than the server limit of 2 MB.']);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['files' => 'Each file must be 2 MB or smaller.']);
        }
        if (! $this->guard->accepts($file, $this->types())) {
            throw ValidationException::withMessages(['files' => 'This file type is not allowed.']);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $disk = (string) config('uploads.disk');
        $storedName = Str::uuid()->toString().'.'.$extension;
        $directory = sprintf('company/%d/chat/%s', $companyId, now()->format('Y/m'));
        $path = $file->storeAs($directory, $storedName, ['disk' => $disk]);
        if ($path === false) {
            throw ValidationException::withMessages(['files' => 'The file could not be stored.']);
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'stored_name' => $storedName,
            'mime' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
        ];
    }

    /**
     * @param  array{disk: string, path: string}  $stored
     */
    public function discard(array $stored): void
    {
        Storage::disk($stored['disk'])->delete($stored['path']);
    }

    public function respond(object $file, bool $inline): StreamedResponse
    {
        $storage = Storage::disk($file->disk);
        abort_unless($storage->exists($file->path), 404);

        $stream = $storage->readStream($file->path);
        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);
        if (! hash_equals($file->checksum, hash_final($context))) {
            Log::warning('Chat attachment failed its integrity check.', ['path' => $file->path]);
            abort(409, 'The stored file failed its integrity check and cannot be served.');
        }

        $extension = strtolower(pathinfo($file->stored_name, PATHINFO_EXTENSION));
        $inline = $inline && in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true);
        $name = str_replace(['"', "\r", "\n"], '', (string) $file->original_name);

        return $storage->response($file->path, $name !== '' ? $name : $file->stored_name, [
            'Content-Type' => $inline ? $file->mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $inline ? 'inline' : 'attachment');
    }

    /**
     * @return array<string, list<string>>
     */
    private function types(): array
    {
        $keep = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'dwg', 'dxf'];

        return array_intersect_key(config('uploads.allowed'), array_flip($keep)) + ['txt' => ['text/plain']];
    }
}
