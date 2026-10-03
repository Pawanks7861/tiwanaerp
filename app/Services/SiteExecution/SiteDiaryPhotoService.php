<?php

namespace App\Services\SiteExecution;

use App\Models\SiteExecution\SiteDiary;
use App\Models\SiteExecution\SiteDiaryPhoto;
use App\Services\Attachments\FileTypeGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Site photos on the private disk under company/{id}/site-diaries/Y/m/{uuid}.{ext}, with a small
 * JPEG thumbnail made synchronously (GD). Uploads are idempotent on the client uuid so a mobile
 * retry does not duplicate a photo. Files are only served through the authorised controller.
 */
class SiteDiaryPhotoService
{
    public const MAX_PER_DIARY = 30;

    private const THUMB_EDGE = 360;

    public function __construct(private readonly FileTypeGuard $guard) {}

    /**
     * @param  array{uuid?: ?string, caption?: ?string, latitude?: mixed, longitude?: mixed, taken_at?: ?string}  $meta
     */
    public function store(SiteDiary $diary, UploadedFile $file, array $meta = []): SiteDiaryPhoto
    {
        $diary->assertEditable();

        $uuid = $meta['uuid'] ?? null;
        if ($uuid !== null && ($existing = SiteDiaryPhoto::query()->where('uuid', $uuid)->first())) {
            if ((int) $existing->site_diary_id !== (int) $diary->id) {
                throw ValidationException::withMessages(['photo' => 'This photo reference is already in use.']);
            }

            return $existing;
        }

        if (! $this->guard->isImage($file) || ! $this->guard->isAllowed($file)) {
            throw ValidationException::withMessages(['photo' => 'Upload a JPG, PNG or WebP image.']);
        }
        if (SiteDiaryPhoto::query()->where('site_diary_id', $diary->id)->count() >= self::MAX_PER_DIARY) {
            throw ValidationException::withMessages(['photo' => 'A diary can hold at most '.self::MAX_PER_DIARY.' photos.']);
        }

        $uuid ??= (string) Str::uuid();
        $disk = config('uploads.disk');
        $extension = strtolower($file->getClientOriginalExtension());
        $directory = sprintf('company/%d/site-diaries/%s', $diary->company_id, now()->format('Y/m'));
        $path = $file->storeAs($directory, "{$uuid}.{$extension}", ['disk' => $disk]);
        $thumbnail = $this->thumbnail($file, $disk, "{$directory}/{$uuid}_thumb.jpg");

        try {
            return DB::transaction(function () use ($diary, $file, $meta, $uuid, $disk, $path, $thumbnail) {
                $photo = new SiteDiaryPhoto;
                $photo->forceFill([
                    'uuid' => $uuid,
                    'site_diary_id' => $diary->id,
                    'disk' => $disk,
                    'path' => $path,
                    'thumbnail_path' => $thumbnail,
                    'mime' => (string) $file->getMimeType(),
                    'caption' => $meta['caption'] ?? null,
                    'latitude' => $meta['latitude'] ?? null,
                    'longitude' => $meta['longitude'] ?? null,
                    'taken_at' => $meta['taken_at'] ?? now(),
                    'size_bytes' => (int) $file->getSize(),
                    'created_by' => Auth::id(),
                ])->save();

                return $photo;
            });
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete(array_filter([$path, $thumbnail]));
            throw $e;
        }
    }

    public function delete(SiteDiaryPhoto $photo): void
    {
        DB::transaction(fn () => $photo->delete());
        Storage::disk($photo->disk)->delete(array_filter([$photo->path, $photo->thumbnail_path]));
    }

    public function stream(SiteDiaryPhoto $photo, bool $thumbnail = false): StreamedResponse
    {
        $useThumb = $thumbnail && $photo->thumbnail_path !== null;
        $path = $useThumb ? $photo->thumbnail_path : $photo->path;

        return Storage::disk($photo->disk)->response($path, basename($path), [
            'Content-Type' => $useThumb ? 'image/jpeg' : $photo->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Best effort: a photo without a thumbnail is still stored (the full image is shown instead).
     */
    private function thumbnail(UploadedFile $file, string $disk, string $path): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($source === false) {
            return null;
        }

        try {
            $source = $this->orient($source, $file);
            [$width, $height] = [imagesx($source), imagesy($source)];
            $scale = min(1, self::THUMB_EDGE / max($width, $height));
            $thumb = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            imagefill($thumb, 0, 0, (int) imagecolorallocate($thumb, 255, 255, 255));
            imagecopyresampled($thumb, $source, 0, 0, 0, 0, imagesx($thumb), imagesy($thumb), $width, $height);

            ob_start();
            imagejpeg($thumb, null, 78);
            $bytes = (string) ob_get_clean();
            imagedestroy($thumb);

            return Storage::disk($disk)->put($path, $bytes) ? $path : null;
        } finally {
            imagedestroy($source);
        }
    }

    private function orient(\GdImage $image, UploadedFile $file): \GdImage
    {
        if (! function_exists('exif_read_data') || ! in_array(strtolower($file->getClientOriginalExtension()), ['jpg', 'jpeg'], true)) {
            return $image;
        }

        $orientation = (int) (@exif_read_data($file->getRealPath())['Orientation'] ?? 1);
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image;
        }
        imagedestroy($image);

        return $rotated;
    }
}
