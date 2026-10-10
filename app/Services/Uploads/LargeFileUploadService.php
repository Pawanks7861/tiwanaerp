<?php

namespace App\Services\Uploads;

use App\Contracts\Files\FileSecurityScannerInterface;
use App\Http\Controllers\Core\AttachmentController;
use App\Models\Chat\Conversation;
use App\Models\Core\Attachment;
use App\Models\Documents\Document;
use App\Models\Documents\Drawing;
use App\Models\Projects\Project;
use App\Models\SiteExecution\SiteDiary;
use App\Models\Uploads\UploadSession;
use App\Models\User;
use App\Services\Attachments\FileTypeGuard;
use App\Services\Chat\ChatService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * One chunked, resumable upload pipeline for the ERP. Controllers authorize and delegate.
 * Bytes stay on the private disk. A session is finalized only after every chunk is present,
 * the streamed SHA-256 matches, and the destination is still allowed.
 */
class LargeFileUploadService
{
    public function __construct(
        private readonly FileTypeGuard $guard,
        private readonly FileSecurityScannerInterface $scanner,
    ) {}

    /**
     * @param  array{original_name: string, total_size: int, module: string, source_type?: ?string, source_id?: ?int, declared_mime?: ?string}  $input
     */
    public function initialize(User $user, array $input): UploadSession
    {
        $name = $this->guard->originalName($input['original_name']);
        $extension = $this->guard->extensionOf($name);
        $size = (int) $input['total_size'];
        $max = (int) config('uploads.max_file_size_bytes');

        if ($size < 1 || $size > $max) {
            throw ValidationException::withMessages(['file' => 'File exceeds 1 GB']);
        }

        $chunkBytes = max(1, (int) config('uploads.chunk_bytes'));
        $totalChunks = (int) ceil($size / $chunkBytes);
        if ($totalChunks > (int) config('uploads.max_chunks')) {
            throw ValidationException::withMessages(['file' => 'File exceeds 1 GB']);
        }

        $module = (string) $input['module'];
        $sourceType = $input['source_type'] ?? null;
        $sourceId = isset($input['source_id']) ? (int) $input['source_id'] : null;
        $this->authorize($user, $module, $sourceType, $sourceId, $extension);

        $this->assertCapacity($user, $size);

        $id = (string) Str::uuid();
        $session = new UploadSession;
        $session->forceFill([
            'id' => $id,
            'user_id' => $user->id,
            'module' => $module,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'original_name' => Str::limit($name, 200, ''),
            'extension' => $extension,
            'declared_mime' => $input['declared_mime'] ?? null,
            'total_size' => $size,
            'chunk_bytes' => $chunkBytes,
            'total_chunks' => $totalChunks,
            'uploaded_chunks' => 0,
            'chunk_checksums' => [],
            'temp_path' => 'uploads/tmp/'.$id,
            'disk' => (string) config('uploads.disk'),
            'status' => 'initialized',
            'scan_status' => $this->scanner->status(),
            'expires_at' => now()->addHours((int) config('uploads.expire_hours')),
        ])->save();

        return $session;
    }

    public function receive(User $user, UploadSession $session, int $number, UploadedFile $chunk, ?string $checksum): UploadSession
    {
        $session = $this->open($user, $session);

        if ($number < 1 || $number > (int) $session->total_chunks) {
            throw ValidationException::withMessages(['chunk' => 'Upload interrupted — Retry']);
        }

        $size = (int) $chunk->getSize();
        if ($size < 1 || $size > (int) $session->chunk_bytes) {
            throw ValidationException::withMessages(['chunk' => 'Upload interrupted — Retry']);
        }

        $actual = hash_file('sha256', $chunk->getRealPath());
        if (is_string($checksum) && $checksum !== '' && ! hash_equals($actual, strtolower($checksum))) {
            throw ValidationException::withMessages(['chunk' => 'File corrupted during upload']);
        }

        $disk = Storage::disk($session->disk);
        $relative = $session->temp_path.'/'.$number;
        $parts = $session->chunk_checksums ?? [];
        $key = (string) $number;

        if ($disk->exists($relative) && isset($parts[$key]['sha256']) && hash_equals($parts[$key]['sha256'], $actual)) {
            return $session;
        }
        if ($disk->exists($relative)) {
            throw ValidationException::withMessages(['chunk' => 'File corrupted during upload']);
        }

        $stream = fopen($chunk->getRealPath(), 'rb');
        if ($stream === false) {
            throw ValidationException::withMessages(['file' => 'Storage unavailable']);
        }
        try {
            $disk->writeStream($relative, $stream);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'Storage unavailable']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $parts[$key] = ['sha256' => $actual, 'size' => $size];
        $session->forceFill([
            'chunk_checksums' => $parts,
            'uploaded_chunks' => count($parts),
            'status' => 'uploading',
        ])->save();

        return $session;
    }

    public function complete(User $user, UploadSession $session, ?string $checksum, ?string $category = null): UploadSession
    {
        $session = $this->open($user, $session);
        if ($session->status === 'completed') {
            return $session;
        }

        $claimed = UploadSession::query()
            ->whereKey($session->id)
            ->whereIn('status', ['initialized', 'uploading'])
            ->update(['status' => 'processing']);

        if ($claimed === 0) {
            $session->refresh();
            if ($session->status === 'completed') {
                return $session;
            }
            throw ValidationException::withMessages(['file' => 'Upload interrupted — Retry']);
        }

        $session->refresh();

        try {
            $this->authorize(
                $user,
                $session->module,
                $session->source_type,
                $session->source_id !== null ? (int) $session->source_id : null,
                $session->extension,
            );
            $this->assertSpace((int) $session->total_size);
            $ready = $this->assemble($session);
            $this->bind($session, $ready['path'], $ready['checksum'], $ready['mime'], $category, $checksum);
        } catch (ValidationException $e) {
            $this->settle($session, $e->errors());
            throw $e;
        } catch (AuthorizationException|HttpException $e) {
            $this->revert($session);
            throw $e;
        } catch (\Throwable) {
            if ($session->fresh()?->status === 'processing') {
                $session->forceFill(['status' => 'failed'])->save();
            }
            throw ValidationException::withMessages(['file' => 'Storage unavailable']);
        }

        return $session->fresh();
    }

    public function status(User $user, UploadSession $session): UploadSession
    {
        $this->owned($user, $session);
        if ($session->expires_at->isPast() && ! in_array($session->status, ['completed', 'cancelled', 'expired'], true)) {
            $session->forceFill(['status' => 'expired'])->save();
        }

        return $session;
    }

    public function cancel(User $user, UploadSession $session): void
    {
        $this->owned($user, $session);
        if ($session->status === 'completed' && $session->module === 'attachment') {
            throw ValidationException::withMessages(['file' => 'Permission denied']);
        }
        if (in_array($session->status, ['cancelled', 'expired'], true)) {
            return;
        }

        $this->deleteTemporary($session);
        $session->forceFill([
            'status' => 'cancelled',
            'final_path' => $session->module === 'attachment' ? $session->final_path : null,
        ])->save();
    }

    public function claim(User $user, string $id): UploadedFile
    {
        $session = $this->owned($user, $this->find($id));
        if ($session->status !== 'completed' || $session->final_path === null) {
            throw ValidationException::withMessages(['upload_id' => 'Upload interrupted — Retry']);
        }
        $this->authorize(
            $user,
            $session->module,
            $session->source_type,
            $session->source_id !== null ? (int) $session->source_id : null,
            $session->extension,
        );

        $absolute = $this->absolute($session->disk, $session->final_path);
        if (! is_file($absolute)) {
            throw ValidationException::withMessages(['file' => 'Storage unavailable']);
        }

        return new UploadedFile(
            $absolute,
            $session->original_name,
            $session->detected_mime ?: 'application/octet-stream',
            UPLOAD_ERR_OK,
            true,
        );
    }

    public function fileFromRequest(Request $request, string $fileKey = 'file', string $idKey = 'upload_id'): UploadedFile
    {
        if ($request->hasFile($fileKey)) {
            return $request->file($fileKey);
        }

        $id = $request->input($idKey);
        if (! is_string($id) || $id === '') {
            throw ValidationException::withMessages([$fileKey => 'Choose a file.']);
        }

        return $this->claim($request->user(), $id);
    }

    public function release(User $user, mixed $id): void
    {
        if (! is_string($id) || $id === '') {
            return;
        }

        $session = UploadSession::query()->whereKey($id)->first();
        if ($session === null || (int) $session->user_id !== (int) $user->id) {
            return;
        }
        if ($session->module === 'attachment' || $session->final_path === null) {
            return;
        }
        if (str_starts_with($session->final_path, 'company/')) {
            return;
        }

        Storage::disk($session->disk)->delete($session->final_path);
        $session->forceFill(['final_path' => null])->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(UploadSession $session): array
    {
        $received = array_map('intval', array_keys($session->chunk_checksums ?? []));
        sort($received);

        $payload = [
            'id' => $session->id,
            'status' => $session->status,
            'module' => $session->module,
            'original_name' => $session->original_name,
            'total_size' => (int) $session->total_size,
            'chunk_bytes' => (int) $session->chunk_bytes,
            'total_chunks' => (int) $session->total_chunks,
            'uploaded_chunks' => count($received),
            'received' => $received,
            'checksum' => $session->checksum,
            'scan_status' => $session->scan_status,
            'expires_at' => $session->expires_at?->toIso8601String(),
        ];

        if ($session->module === 'attachment' && $session->final_path !== null) {
            $payload['attachment_id'] = Attachment::query()->where('path', $session->final_path)->value('id');
        }

        return $payload;
    }

    public function purgeExpired(): int
    {
        $sessions = UploadSession::withoutGlobalScopes()
            ->where('status', '!=', 'completed')
            ->where(function ($query) {
                $query->where('expires_at', '<', now())
                    ->orWhere('status', 'cancelled')
                    ->orWhere('status', 'failed');
            })
            ->get();

        $count = 0;
        foreach ($sessions as $session) {
            $this->deleteTemporary($session);
            if (! in_array($session->status, ['cancelled', 'failed'], true)) {
                $session->forceFill(['status' => 'expired'])->save();
            }
            $count++;
        }

        return $count;
    }

    private function open(User $user, UploadSession $session): UploadSession
    {
        $session = $this->owned($user, $session);
        if ($session->status === 'cancelled') {
            throw ValidationException::withMessages(['file' => 'Upload interrupted — Retry']);
        }
        if ($session->status === 'expired' || ($session->expires_at->isPast() && $session->status !== 'completed')) {
            $session->forceFill(['status' => 'expired'])->save();
            throw ValidationException::withMessages(['file' => 'Upload expired']);
        }
        if ($session->status === 'failed') {
            throw ValidationException::withMessages(['file' => 'Upload interrupted — Retry']);
        }

        return $session;
    }

    private function owned(User $user, UploadSession $session): UploadSession
    {
        if ((int) $session->user_id !== (int) $user->id) {
            abort(403, 'Permission denied');
        }

        return $session;
    }

    private function find(string $id): UploadSession
    {
        $session = UploadSession::query()->whereKey($id)->first();
        abort_unless($session !== null, 404);

        return $session;
    }

    private function authorize(User $user, string $module, ?string $sourceType, ?int $sourceId, string $extension): void
    {
        if ($module === 'site_photo' && ! in_array($extension, config('uploads.image_extensions'), true)) {
            throw ValidationException::withMessages(['file' => 'File type not permitted for security reasons']);
        }

        match ($module) {
            'generic' => null,
            'attachment' => $this->authorizeAttachment((string) $sourceType, (int) $sourceId),
            'chat' => $this->authorizeChat($user, (int) $sourceId),
            'document' => $this->authorizeDocument($sourceType, (int) $sourceId),
            'drawing' => $this->authorizeDrawing($sourceType, (int) $sourceId),
            'site_photo' => $this->authorizeDiary((int) $sourceId),
            default => throw ValidationException::withMessages(['module' => 'Permission denied']),
        };
    }

    private function authorizeAttachment(string $sourceType, int $sourceId): void
    {
        if (! in_array($sourceType, AttachmentController::ATTACHABLE, true)) {
            throw ValidationException::withMessages(['source_type' => 'Permission denied']);
        }

        /** @var class-string<Model>|null $class */
        $class = Relation::getMorphedModel($sourceType);
        if ($class === null) {
            throw ValidationException::withMessages(['source_type' => 'Permission denied']);
        }
        $record = $class::query()->find($sourceId);
        abort_unless($record !== null, 404);
        Gate::authorize('update', $record);
    }

    private function authorizeChat(User $user, int $sourceId): void
    {
        $conversation = Conversation::query()->find($sourceId);
        abort_unless($conversation !== null, 404);
        app(ChatService::class)->participant($conversation, $user);
    }

    private function authorizeDocument(?string $sourceType, int $sourceId): void
    {
        if ($sourceType === 'document') {
            $document = Document::query()->find($sourceId);
            abort_unless($document !== null, 404);
            Gate::authorize('addVersion', $document);

            return;
        }

        $project = Project::query()->find($sourceId);
        abort_unless($project !== null, 404);
        Gate::authorize('create', [Document::class, $project]);
    }

    private function authorizeDrawing(?string $sourceType, int $sourceId): void
    {
        if ($sourceType === 'drawing') {
            $drawing = Drawing::query()->find($sourceId);
            abort_unless($drawing !== null, 404);
            Gate::authorize('upload', $drawing);

            return;
        }

        $project = Project::query()->find($sourceId);
        abort_unless($project !== null, 404);
        Gate::authorize('create', [Drawing::class, $project]);
    }

    private function authorizeDiary(int $diaryId): void
    {
        $diary = SiteDiary::query()->find($diaryId);
        abort_unless($diary !== null, 404);
        Gate::authorize('update', $diary);
    }

    private function assertCapacity(User $user, int $bytes): void
    {
        UploadSession::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['initialized', 'uploading', 'processing'])
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);

        $active = UploadSession::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['initialized', 'uploading', 'processing'])
            ->count();

        if ($active >= (int) config('uploads.max_active_sessions')) {
            throw ValidationException::withMessages(['file' => 'Upload interrupted — Retry']);
        }

        $quota = config('uploads.company_quota_bytes');
        if (is_numeric($quota)) {
            $used = (int) Attachment::query()->sum('size_bytes');
            $pending = (int) UploadSession::query()
                ->whereIn('status', ['initialized', 'uploading', 'processing'])
                ->sum('total_size');
            if ($used + $pending + $bytes > (int) $quota) {
                throw ValidationException::withMessages(['file' => 'Insufficient storage space']);
            }
        }

        $this->assertSpace($bytes);
    }

    private function assertSpace(int $bytes): void
    {
        $free = @disk_free_space(storage_path('app'));
        if ($free === false) {
            return;
        }
        if ($free < ($bytes + (64 * 1024 * 1024))) {
            throw ValidationException::withMessages(['file' => 'Insufficient storage space']);
        }
    }

    /**
     * @return array{path: string, checksum: string, mime: string}
     */
    private function assemble(UploadSession $session): array
    {
        $disk = Storage::disk($session->disk);
        $parts = $session->chunk_checksums ?? [];
        $expected = 0;

        for ($number = 1; $number <= (int) $session->total_chunks; $number++) {
            $key = (string) $number;
            if (! isset($parts[$key]) || ! $disk->exists($session->temp_path.'/'.$number)) {
                throw ValidationException::withMessages(['file' => 'Upload interrupted — Retry']);
            }
            $expected += (int) $parts[$key]['size'];
        }

        if ($expected !== (int) $session->total_size) {
            throw ValidationException::withMessages(['file' => 'File corrupted during upload']);
        }

        $relative = 'uploads/ready/'.$session->id.'.'.$session->extension;
        $absolute = $this->absolute($session->disk, $relative);
        if (! is_dir(dirname($absolute)) && ! mkdir(dirname($absolute), 0755, true) && ! is_dir(dirname($absolute))) {
            throw ValidationException::withMessages(['file' => 'Storage unavailable']);
        }

        $out = fopen($absolute, 'wb');
        if ($out === false) {
            throw ValidationException::withMessages(['file' => 'Storage unavailable']);
        }

        $hash = hash_init('sha256');
        try {
            for ($number = 1; $number <= (int) $session->total_chunks; $number++) {
                $in = $disk->readStream($session->temp_path.'/'.$number);
                if ($in === false) {
                    throw ValidationException::withMessages(['file' => 'Storage unavailable']);
                }
                try {
                    while (! feof($in)) {
                        $buffer = fread($in, 1024 * 1024);
                        if ($buffer === false) {
                            throw ValidationException::withMessages(['file' => 'File corrupted during upload']);
                        }
                        if ($buffer === '') {
                            break;
                        }
                        hash_update($hash, $buffer);
                        if (fwrite($out, $buffer) === false) {
                            throw ValidationException::withMessages(['file' => 'Storage unavailable']);
                        }
                    }
                } finally {
                    if (is_resource($in)) {
                        fclose($in);
                    }
                }
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($absolute);
            throw $e;
        }
        fclose($out);

        $digest = hash_final($hash);
        if (is_file($absolute) && filesize($absolute) !== (int) $session->total_size) {
            @unlink($absolute);
            throw ValidationException::withMessages(['file' => 'File corrupted during upload']);
        }

        if ($this->dangerous($absolute) || $this->mismatchedKnownType($absolute, $session->extension)) {
            @unlink($absolute);
            throw ValidationException::withMessages(['file' => 'File type not permitted for security reasons']);
        }

        $disk->deleteDirectory($session->temp_path);

        return ['path' => $relative, 'checksum' => $digest, 'mime' => $this->mime($absolute, $session->extension)];
    }

    private function bind(UploadSession $session, string $readyPath, string $digest, string $mime, ?string $category, ?string $checksum): void
    {
        if (is_string($checksum) && $checksum !== '' && ! hash_equals($digest, strtolower($checksum))) {
            $this->deleteReady($session->disk, $readyPath);
            throw ValidationException::withMessages(['file' => 'Final checksum mismatch']);
        }

        if ($session->module === 'attachment') {
            $this->bindAttachment($session, $readyPath, $digest, $mime, $category);

            return;
        }

        $session->forceFill([
            'status' => 'completed',
            'final_path' => $readyPath,
            'checksum' => $digest,
            'detected_mime' => $mime,
            'scan_status' => $this->scanner->status(),
            'completed_at' => now(),
        ])->save();
    }

    private function bindAttachment(UploadSession $session, string $readyPath, string $digest, string $mime, ?string $category): void
    {
        $class = Relation::getMorphedModel((string) $session->source_type);
        if ($class === null) {
            throw ValidationException::withMessages(['file' => 'Permission denied']);
        }
        $record = $class::query()->find($session->source_id);
        abort_unless($record !== null, 404);

        $final = sprintf(
            'company/%d/%s/%s/%s.%s',
            $session->company_id,
            $session->source_type,
            now()->format('Y/m'),
            (string) Str::uuid(),
            $session->extension,
        );

        $from = $this->absolute($session->disk, $readyPath);
        $to = $this->absolute($session->disk, $final);
        if (! is_dir(dirname($to)) && ! mkdir(dirname($to), 0755, true) && ! is_dir(dirname($to))) {
            throw ValidationException::withMessages(['file' => 'Storage unavailable']);
        }
        if (! rename($from, $to)) {
            throw ValidationException::withMessages(['file' => 'Storage unavailable']);
        }

        try {
            DB::transaction(function () use ($session, $record, $final, $digest, $mime, $category) {
                $attachment = new Attachment;
                $attachment->forceFill([
                    'attachable_type' => $session->source_type,
                    'attachable_id' => $record->getKey(),
                    'category' => $category !== null && $category !== '' ? Str::limit($category, 50, '') : null,
                    'disk' => $session->disk,
                    'path' => $final,
                    'original_name' => $session->original_name,
                    'mime' => $mime,
                    'extension' => $session->extension,
                    'size_bytes' => (int) $session->total_size,
                    'checksum' => $digest,
                    'uploaded_by' => $session->user_id,
                ])->save();

                $session->forceFill([
                    'status' => 'completed',
                    'final_path' => $final,
                    'checksum' => $digest,
                    'detected_mime' => $mime,
                    'scan_status' => $this->scanner->status(),
                    'completed_at' => now(),
                ])->save();
            });
        } catch (ValidationException $e) {
            Storage::disk($session->disk)->delete($final);
            throw $e;
        } catch (\Throwable) {
            Storage::disk($session->disk)->delete($final);
            throw ValidationException::withMessages(['file' => 'Storage unavailable']);
        }
    }

    private function dangerous(string $absolute): bool
    {
        $handle = fopen($absolute, 'rb');
        if ($handle === false) {
            return true;
        }
        $head = (string) fread($handle, 64);
        fclose($handle);

        if (str_starts_with($head, '<?php') || str_starts_with($head, '<? ') || str_starts_with($head, "\x7FELF") || str_starts_with($head, 'MZ')) {
            return true;
        }

        $mime = (string) ((new \finfo(FILEINFO_MIME_TYPE))->file($absolute) ?: '');

        return str_contains($mime, 'php')
            || str_contains($mime, 'x-executable')
            || str_contains($mime, 'x-sharedlib')
            || str_contains($mime, 'x-dosexec')
            || str_contains($mime, 'x-mach-binary');
    }

    private function mismatchedKnownType(string $absolute, string $extension): bool
    {
        $known = config('uploads.allowed')[$extension] ?? null;
        if (! is_array($known)) {
            return false;
        }
        $detected = (string) ((new \finfo(FILEINFO_MIME_TYPE))->file($absolute) ?: '');

        return ! in_array($detected, $known, true);
    }

    private function mime(string $absolute, string $extension): string
    {
        $detected = (string) ((new \finfo(FILEINFO_MIME_TYPE))->file($absolute) ?: '');
        $known = config('uploads.allowed')[$extension] ?? null;
        if (is_array($known) && in_array($detected, $known, true)) {
            return $detected;
        }

        return $detected !== '' ? $detected : 'application/octet-stream';
    }

    private function absolute(string $disk, string $relative): string
    {
        try {
            return Storage::disk($disk)->path($relative);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'Storage unavailable']);
        }
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function settle(UploadSession $session, array $errors): void
    {
        if ($session->fresh()?->status !== 'processing') {
            return;
        }

        $message = (string) (collect($errors)->flatten()->first() ?? '');
        $hard = [
            'File corrupted during upload',
            'File type not permitted for security reasons',
            'Final checksum mismatch',
            'Storage unavailable',
            'Insufficient storage space',
        ];
        $session->forceFill(['status' => in_array($message, $hard, true) ? 'failed' : 'uploading'])->save();
    }

    private function revert(UploadSession $session): void
    {
        if ($session->fresh()?->status === 'processing') {
            $session->forceFill(['status' => 'uploading'])->save();
        }
    }

    private function deleteReady(string $disk, string $relative): void
    {
        Storage::disk($disk)->delete($relative);
    }

    private function deleteTemporary(UploadSession $session): void
    {
        $disk = Storage::disk($session->disk);
        if ($session->temp_path !== '') {
            $disk->deleteDirectory($session->temp_path);
        }
        if (is_string($session->final_path) && str_starts_with($session->final_path, 'uploads/')) {
            $disk->delete($session->final_path);
        }
    }
}
