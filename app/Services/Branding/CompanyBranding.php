<?php

namespace App\Services\Branding;

use App\Models\Core\Company;
use App\Services\Attachments\FileTypeGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Company logo and favicon. Files stay on the private disk under company/{id}/branding and are
 * served only for the active company. SVG is refused: it cannot be sanitized safely here.
 * These are image assets, not generic attachments, so they stay on a small single-request cap.
 */
class CompanyBranding
{
    public const MAX_KB = 2048;

    public function __construct(private readonly FileTypeGuard $guard) {}

    public function storeLogo(Company $company, UploadedFile $file): void
    {
        $this->store($company, $file, 'logo_path', $this->imageTypes(), (int) config('uploads.logo_max_kb'));
    }

    public function storeFavicon(Company $company, UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension === 'ico') {
            $this->assertSize($file, (int) config('uploads.favicon_max_kb'));
            if (! $this->hasIcoSignature($file)) {
                throw ValidationException::withMessages(['file' => 'This file type is not allowed.']);
            }
            $this->write($company, $file, 'favicon_path', 'image/x-icon');

            return;
        }

        $this->store($company, $file, 'favicon_path', $this->imageTypes(), (int) config('uploads.favicon_max_kb'));
    }

    public function removeLogo(Company $company): void
    {
        $this->remove($company, 'logo_path');
    }

    public function removeFavicon(Company $company): void
    {
        $this->remove($company, 'favicon_path');
    }

    public function response(Company $company, string $kind): StreamedResponse
    {
        $column = $kind === 'favicon' ? 'favicon_path' : 'logo_path';
        $path = (string) $company->{$column};
        abort_unless($this->ownedPath($company, $path), 404);

        $disk = Storage::disk(config('uploads.disk'));
        abort_unless($disk->exists($path), 404);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $disk->response($path, $kind.'.'.$extension, [
            'Content-Type' => $this->mimeFor($extension),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    /**
     * PNG or JPEG data URI for PDF headers. WebP is converted when GD can; otherwise omitted.
     */
    public function dataUri(?Company $company): ?string
    {
        if ($company === null || blank($company->logo_path) || ! $this->ownedPath($company, (string) $company->logo_path)) {
            return null;
        }

        $disk = Storage::disk(config('uploads.disk'));
        if (! $disk->exists($company->logo_path)) {
            return null;
        }

        $extension = strtolower(pathinfo((string) $company->logo_path, PATHINFO_EXTENSION));
        $bytes = $disk->get($company->logo_path);
        if ($bytes === null) {
            return null;
        }

        if ($extension === 'webp' && function_exists('imagecreatefromwebp')) {
            $image = @imagecreatefromstring($bytes);
            if ($image === false) {
                return null;
            }
            ob_start();
            imagepng($image);
            imagedestroy($image);
            $bytes = ob_get_clean();
            $extension = 'png';
        }

        if (! in_array($extension, ['png', 'jpg', 'jpeg'], true)) {
            return null;
        }

        $mime = $extension === 'png' ? 'image/png' : 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    /**
     * @param  array<string, list<string>>  $types
     */
    private function store(Company $company, UploadedFile $file, string $column, array $types, int $maxKb): void
    {
        $this->assertSize($file, $maxKb);
        if (! $this->guard->accepts($file, $types)) {
            throw ValidationException::withMessages(['file' => 'Use a PNG, JPG or WEBP image.']);
        }

        $this->write($company, $file, $column, (string) $file->getMimeType());
    }

    private function write(Company $company, UploadedFile $file, string $column, string $mime): void
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $diskName = (string) config('uploads.disk');
        $directory = sprintf('company/%d/branding', $company->id);
        $stored = $file->storeAs($directory, Str::uuid()->toString().'.'.$extension, ['disk' => $diskName]);
        if ($stored === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be stored.']);
        }

        $previous = $company->{$column};
        $company->forceFill([$column => $stored])->save();

        if (is_string($previous) && $previous !== '' && $previous !== $stored) {
            Storage::disk($diskName)->delete($previous);
        }

        unset($mime);
    }

    private function remove(Company $company, string $column): void
    {
        $path = $company->{$column};
        $company->forceFill([$column => null])->save();
        if (is_string($path) && $path !== '') {
            Storage::disk(config('uploads.disk'))->delete($path);
        }
    }

    private function assertSize(UploadedFile $file, int $maxKb): void
    {
        $label = max(1, (int) round($maxKb / 1024));
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => "The file is larger than the server limit of {$label} MB."]);
        }
        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages(['file' => "The file must be {$label} MB or smaller."]);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function imageTypes(): array
    {
        return array_intersect_key(config('uploads.allowed'), array_flip(['png', 'jpg', 'jpeg', 'webp']));
    }

    private function hasIcoSignature(UploadedFile $file): bool
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            return false;
        }
        $head = (string) fread($handle, 4);
        fclose($handle);

        return $head === "\x00\x00\x01\x00";
    }

    private function ownedPath(Company $company, string $path): bool
    {
        return $path !== '' && str_starts_with($path, 'company/'.$company->id.'/branding/') && ! str_contains($path, '..');
    }

    private function mimeFor(string $extension): string
    {
        return match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            default => 'application/octet-stream',
        };
    }
}
