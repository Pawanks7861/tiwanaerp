<?php

namespace App\Services\Attachments;

use App\Models\Core\Attachment;
use App\Services\Files\FilePreviewService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentService
{
    public function __construct(private readonly FileTypeGuard $guard) {}

    /**
     * Store an uploaded file against a tenant-owned record.
     *
     * @param  Model&object{company_id:int}  $attachable
     */
    public function store(Model $attachable, UploadedFile $file, ?string $category = null): Attachment
    {
        if (! $this->guard->isAllowed($file)) {
            throw ValidationException::withMessages(['file' => 'This file type is not allowed.']);
        }

        $disk = config('uploads.disk');
        $extension = strtolower($file->getClientOriginalExtension());
        $module = Str::kebab(class_basename($attachable));
        $directory = sprintf('company/%d/%s/%s', $attachable->getAttribute('company_id'), $module, now()->format('Y/m'));
        $storedName = Str::uuid()->toString().'.'.$extension;

        $path = $file->storeAs($directory, $storedName, ['disk' => $disk]);

        try {
            $attachment = DB::transaction(fn () => $attachable->attachments()->create([
                'category' => $category,
                'disk' => $disk,
                'path' => $path,
                'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                'mime' => (string) $file->getMimeType(),
                'extension' => $extension,
                'size_bytes' => $file->getSize(),
                'checksum' => hash_file('sha256', $file->getRealPath()),
                'uploaded_by' => Auth::id(),
            ]));
            app(FilePreviewService::class)->enqueue('attachment', (int) $attachment->id);

            return $attachment;
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path);
            throw $e;
        }
    }

    public function download(Attachment $attachment): StreamedResponse
    {
        $preview = in_array(strtolower((string) $attachment->extension), config('uploads.preview_extensions'), true);
        $name = str_replace(['"', "\r", "\n"], '', (string) $attachment->original_name);

        return Storage::disk($attachment->disk)->response($attachment->path, $name !== '' ? $name : 'download', [
            'Content-Type' => $preview ? ($attachment->mime ?: 'application/octet-stream') : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $preview ? 'inline' : 'attachment');
    }

    /**
     * Soft delete only: the file is kept for audit history.
     */
    public function delete(Attachment $attachment): void
    {
        DB::transaction(function () use ($attachment) {
            $attachment->forceFill(['deleted_by' => Auth::id()])->save();
            $attachment->delete();
        });
    }
}
