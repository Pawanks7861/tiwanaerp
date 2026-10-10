<?php

namespace App\Services\Files;

use App\Jobs\GenerateFilePreview;
use App\Models\Files\FilePreview;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Chooses a safe preview for one private file and serves it without loading the whole file into a string.
 */
class FilePreviewService
{
    /** @var array<string, string> */
    private const MIME = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogv' => 'video/ogg',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'm4a' => 'audio/mp4',
    ];

    public function __construct(
        private readonly PreviewConverter $converter,
        private readonly TextPreviewProvider $text,
        private readonly SpreadsheetPreviewProvider $sheets,
        private readonly ArchivePreviewProvider $archives,
        private readonly SvgSanitizer $svg,
        private readonly DxfSvgEncoder $dxf,
        private readonly LocalPreviewConverter $staging,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function describe(PreviewableFile $file): array
    {
        $strategy = $this->strategy($file);
        $preview = in_array($strategy, ['office', 'cad'], true) ? $this->prepare($file) : null;
        $status = $preview?->status ?? ($strategy === 'download' ? FilePreview::UNSUPPORTED : FilePreview::READY);
        $message = $preview?->error_message;
        $truncated = false;

        if ($strategy === 'svg') {
            $status = $this->svgReadable($file) ? FilePreview::READY : FilePreview::UNSUPPORTED;
            $message = $status === FilePreview::READY ? null : 'Preview not available';
            if ($status !== FilePreview::READY) {
                $strategy = 'download';
            }
        }

        return [
            'source' => $file->source,
            'id' => $file->id,
            'name' => $file->name,
            'extension' => $file->extension,
            'type_label' => $file->extension !== '' ? strtoupper($file->extension) : 'File',
            'size_bytes' => $file->size,
            'uploaded_by' => $file->uploadedBy,
            'uploaded_at' => $file->uploadedAt,
            'checksum' => $file->checksum,
            'strategy' => $strategy,
            'status' => $status,
            'message' => $message,
            'note' => $strategy === 'cad' && $status === FilePreview::READY
                ? 'Preview generated from '.strtoupper($file->extension).'. Original file unchanged.'
                : null,
            'truncated' => $truncated,
            'scan_status' => 'not_configured',
            'download_url' => route('files.download', ['source' => $file->source, 'id' => $file->id]),
            'stream_url' => route('files.stream', ['source' => $file->source, 'id' => $file->id]),
            'preview_url' => route('files.preview', ['source' => $file->source, 'id' => $file->id]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function structured(PreviewableFile $file): array
    {
        $strategy = $this->strategy($file);

        if ($strategy === 'text') {
            $read = $this->text->read($file);

            return [
                'kind' => 'text',
                'text' => $read['text'],
                'truncated' => $read['truncated'],
                'message' => $read['truncated'] ? 'Large file — preview truncated' : null,
            ];
        }

        if ($strategy === 'csv') {
            $read = $this->text->csv($file);

            return [
                'kind' => 'csv',
                'text' => $read['text'],
                'rows' => $read['rows'],
                'truncated' => $read['truncated'],
                'message' => $read['truncated'] ? 'Large file — preview truncated' : null,
            ];
        }

        if ($strategy === 'spreadsheet') {
            $read = $this->sheets->read($file);

            return ['kind' => 'spreadsheet', ...$read];
        }

        if ($strategy === 'archive') {
            $read = $this->archives->list($file);

            return ['kind' => 'archive', ...$read];
        }

        return ['kind' => 'none', 'message' => 'Preview not available'];
    }

    public function stream(PreviewableFile $file): Response
    {
        $strategy = $this->strategy($file);

        if ($strategy === 'svg') {
            $sanitized = $this->svgDocument($file);
            abort_if($sanitized === null, 404);

            return $this->bytes($sanitized, 'image/svg+xml', $this->safeName($file->name));
        }

        if (in_array($strategy, ['office', 'cad'], true)) {
            $preview = $this->fresh($file);
            abort_unless($preview?->status === FilePreview::READY && $preview->preview_path, 404);

            return $this->file($file->disk, $preview->preview_path, $preview->preview_format === 'svg' ? 'image/svg+xml' : 'application/pdf', $this->safeName($file->name), true);
        }

        abort_unless(isset(self::MIME[$file->extension]) && in_array($strategy, ['image', 'pdf', 'video', 'audio'], true), 404);

        return $this->file($file->disk, $file->path, self::MIME[$file->extension], $this->safeName($file->name), true);
    }

    public function download(PreviewableFile $file): BinaryFileResponse
    {
        return $this->file($file->disk, $file->path, 'application/octet-stream', $this->safeName($file->name), false);
    }

    public function purge(string $source, int $id): void
    {
        $rows = FilePreview::query()->where('source_type', $source)->where('source_id', $id)->get();
        foreach ($rows as $row) {
            if ($row->preview_path) {
                Storage::disk('private')->delete($row->preview_path);
            }
            $row->delete();
        }
    }

    public function strategy(PreviewableFile $file): string
    {
        return match ($file->extension) {
            'jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp' => 'image',
            'svg' => 'svg',
            'pdf' => 'pdf',
            'txt', 'log', 'json', 'xml', 'md' => 'text',
            'csv' => 'csv',
            'xls', 'xlsx' => 'spreadsheet',
            'doc', 'docx', 'ppt', 'pptx' => 'office',
            'dwg', 'dxf' => 'cad',
            'mp4', 'webm', 'ogv' => 'video',
            'mp3', 'wav', 'ogg', 'm4a' => 'audio',
            'zip' => 'archive',
            default => 'download',
        };
    }

    private function prepare(PreviewableFile $file): FilePreview
    {
        $existing = $this->fresh($file);
        if ($existing?->status === FilePreview::READY) {
            return $existing;
        }

        if ($file->extension === 'dxf' && $file->size <= 2_000_000) {
            $svg = $this->dxfSvg($file);
            if ($svg !== null) {
                return $this->storeGenerated($file, $svg, 'svg');
            }
        }

        if (! $this->converterAvailable($file)) {
            return $this->save($file, FilePreview::UNSUPPORTED, $this->unavailableMessage($file), null, null);
        }

        if ($existing !== null && in_array($existing->status, [FilePreview::PENDING, FilePreview::PROCESSING], true) && $existing->updated_at !== null && $existing->updated_at->gt(now()->subMinutes(15))) {
            return $existing;
        }

        $pending = $this->save($file, FilePreview::PENDING, null, null, null);
        GenerateFilePreview::dispatch($file->source, $file->id, $file->companyId);

        return $pending->fresh() ?? $pending;
    }

    public function generate(PreviewableFile $file): void
    {
        $current = $this->fresh($file);
        if ($current?->status === FilePreview::READY) {
            return;
        }

        $this->save($file, FilePreview::PROCESSING, null, $current?->preview_path, $current?->preview_format);

        try {
            if ($file->extension === 'dxf') {
                $svg = $this->dxfSvg($file);
                if ($svg !== null) {
                    $this->storeGenerated($file, $svg, 'svg');

                    return;
                }
            }

            if (! $this->converterAvailable($file)) {
                $this->save($file, FilePreview::UNSUPPORTED, $this->unavailableMessage($file), null, null);

                return;
            }

            $directory = $this->stageDirectory();
            $staged = $directory.DIRECTORY_SEPARATOR.$this->staging->stagedBasename($file->extension);
            $this->copyStream($file, $staged);

            if (in_array($file->extension, ['dwg', 'dxf'], true)) {
                $produced = $this->converter->toSvg($staged, $directory);
                $svg = $this->svg->sanitize($this->readCapped($produced));
                if ($svg === null) {
                    throw new PreviewConversionException(PreviewConversionException::FAILED);
                }
                $this->storeGenerated($file, $svg, 'svg');
            } else {
                $produced = $this->converter->toPdf($staged, $directory);
                $this->storeFile($file, $produced, 'pdf');
            }
        } catch (PreviewConversionException $exception) {
            $status = $exception->safeMessage === PreviewConversionException::UNAVAILABLE ? FilePreview::UNSUPPORTED : FilePreview::FAILED;
            $this->save($file, $status, $exception->safeMessage, null, null);
        } catch (\Throwable) {
            $this->save($file, FilePreview::FAILED, PreviewConversionException::FAILED, null, null);
        } finally {
            if (isset($directory)) {
                Storage::disk('private')->deleteDirectory('previews-tmp/'.basename($directory));
            }
        }
    }

    private function dxfSvg(PreviewableFile $file): ?string
    {
        if ($file->size > 2_000_000 || ! Storage::disk($file->disk)->exists($file->path)) {
            return null;
        }

        $svg = $this->dxf->fromPath(Storage::disk($file->disk)->path($file->path));

        return $svg === null ? null : $this->svg->sanitize($svg);
    }

    private function converterAvailable(PreviewableFile $file): bool
    {
        return in_array($file->extension, ['dwg', 'dxf'], true)
            ? $this->converter->cadAvailable()
            : $this->converter->officeAvailable();
    }

    private function unavailableMessage(PreviewableFile $file): string
    {
        return match ($file->extension) {
            'dwg' => 'DWG preview unavailable on this server',
            'dxf' => 'DXF preview unavailable on this server',
            default => 'Preview not available',
        };
    }

    private function fresh(PreviewableFile $file): ?FilePreview
    {
        $row = FilePreview::query()->where('source_type', $file->source)->where('source_id', $file->id)->first();
        if ($row === null) {
            return null;
        }

        if ($row->status === FilePreview::READY && $file->checksum !== null && $row->source_checksum !== $file->checksum) {
            return null;
        }

        return $row;
    }

    private function storeGenerated(PreviewableFile $file, string $contents, string $format): FilePreview
    {
        $path = sprintf('company/%d/previews/%s/%d/%s.%s', $file->companyId, $file->source, $file->id, Str::uuid()->toString(), $format);
        Storage::disk('private')->put($path, $contents);

        return $this->save($file, FilePreview::READY, null, $path, $format, hash('sha256', $contents));
    }

    private function storeFile(PreviewableFile $file, string $absolute, string $format): FilePreview
    {
        $path = sprintf('company/%d/previews/%s/%d/%s.%s', $file->companyId, $file->source, $file->id, Str::uuid()->toString(), $format);
        $this->copyAbsolute($absolute, $path);

        return $this->save($file, FilePreview::READY, null, $path, $format, hash_file('sha256', $absolute) ?: null);
    }

    private function save(PreviewableFile $file, string $status, ?string $message, ?string $path, ?string $format, ?string $previewChecksum = null): FilePreview
    {
        $row = FilePreview::query()->firstOrNew([
            'source_type' => $file->source,
            'source_id' => $file->id,
        ]);

        if ($row->exists && $row->preview_path && $row->preview_path !== $path) {
            Storage::disk('private')->delete($row->preview_path);
        }

        $row->fill([
            'source_checksum' => $file->checksum,
            'preview_format' => $format,
            'preview_path' => $path,
            'preview_checksum' => $previewChecksum,
            'status' => $status,
            'error_message' => $message,
            'generated_at' => $status === FilePreview::READY ? now() : null,
        ])->save();

        return $row;
    }

    private function stageDirectory(): string
    {
        $relative = 'previews-tmp/'.Str::uuid()->toString();
        Storage::disk('private')->makeDirectory($relative);

        return Storage::disk('private')->path($relative);
    }

    private function copyStream(PreviewableFile $file, string $destination): void
    {
        $source = fopen(Storage::disk($file->disk)->path($file->path), 'rb');
        $target = fopen($destination, 'wb');
        if ($source === false || $target === false) {
            throw new PreviewConversionException(PreviewConversionException::CORRUPTED);
        }

        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);
    }

    private function copyAbsolute(string $absolute, string $relative): void
    {
        $destination = Storage::disk('private')->path($relative);
        if (! is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0755, true);
        }
        $source = fopen($absolute, 'rb');
        $target = fopen($destination, 'wb');
        if ($source === false || $target === false) {
            throw new PreviewConversionException(PreviewConversionException::FAILED);
        }
        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);
    }

    private function readCapped(string $absolute): string
    {
        $handle = fopen($absolute, 'rb');
        if ($handle === false) {
            return '';
        }
        $raw = fread($handle, 2_000_001);
        fclose($handle);

        return is_string($raw) && strlen($raw) <= 2_000_000 ? $raw : '';
    }

    private function svgReadable(PreviewableFile $file): bool
    {
        return $file->size > 0 && $file->size <= 2_000_000 && $this->svgDocument($file) !== null;
    }

    private function svgDocument(PreviewableFile $file): ?string
    {
        if ($file->size > 2_000_000) {
            return null;
        }

        $handle = fopen(Storage::disk($file->disk)->path($file->path), 'rb');
        if ($handle === false) {
            return null;
        }
        $raw = fread($handle, 2_000_001);
        fclose($handle);

        return is_string($raw) ? $this->svg->sanitize($raw) : null;
    }

    private function file(string $disk, string $path, string $mime, string $name, bool $inline): BinaryFileResponse
    {
        $absolute = Storage::disk($disk)->path($path);
        abort_unless(is_file($absolute), 404);

        $response = response()->file($absolute);
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->setContentDisposition($inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name);

        return $response;
    }

    private function bytes(string $body, string $mime, string $name): Response
    {
        $response = response($body, 200, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $name),
        );

        return $response;
    }

    private function safeName(string $name): string
    {
        $name = str_replace(['"', "\r", "\n", '/', '\\', "\0"], '', $name);

        return $name !== '' ? $name : 'download';
    }
}
