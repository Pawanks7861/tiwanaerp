<?php

namespace App\Services\Files\Cad;

use App\Models\Files\FilePreview;
use App\Services\Files\PreviewConversionException;

/**
 * Picks the configured local CAD converter. auto prefers ODA, then LibreDWG.
 * An empty drawing is a failure, and the next configured converter is tried.
 */
class CadPreviewManager
{
    public function __construct(
        private readonly OdaCadConverter $oda,
        private readonly LibreDwgSvgConverter $libre,
        private readonly CadPreviewValidator $validator,
    ) {}

    public function available(): bool
    {
        foreach ($this->candidates() as $converter) {
            if ($converter->available()) {
                return true;
            }
        }

        return false;
    }

    public function convert(string $sourceAbsolute, string $directory): CadPreviewResult
    {
        $last = new PreviewConversionException(PreviewConversionException::UNAVAILABLE);
        $sawConverter = false;

        foreach ($this->candidates() as $converter) {
            if (! $converter->available()) {
                continue;
            }

            $sawConverter = true;
            try {
                $result = $converter->convert($sourceAbsolute, $directory);
            } catch (PreviewConversionException $exception) {
                if ($exception->safeMessage === PreviewConversionException::TIMED_OUT) {
                    throw $exception;
                }
                $last = $exception;

                continue;
            }

            if (! $this->validator->acceptable($result->path, $result->format)) {
                $last = new PreviewConversionException(PreviewConversionException::FAILED);

                continue;
            }

            $size = filesize($result->path);
            if ($result->format === 'svg' && $size !== false && $size > (int) config('previews.cad.max_svg_bytes', 2_000_000)) {
                $last = new PreviewConversionException(PreviewConversionException::LIMITED);

                continue;
            }

            return $result;
        }

        if (! $sawConverter) {
            throw new PreviewConversionException(PreviewConversionException::UNAVAILABLE);
        }

        throw $last;
    }

    /**
     * Admin status. Executable paths are intentionally absent.
     *
     * @return array{available: bool, driver: string, configured: string, version: ?string, last_success_at: ?string, error: ?string}
     */
    public function health(): array
    {
        $chosen = null;
        foreach ($this->candidates() as $converter) {
            if ($converter->available()) {
                $chosen = $converter;
                break;
            }
        }

        $last = FilePreview::query()
            ->where('status', FilePreview::READY)
            ->where('preview_format', 'svg')
            ->orderByDesc('generated_at')
            ->first();

        return [
            'available' => $chosen !== null,
            'driver' => $chosen?->key() ?? 'none',
            'configured' => $this->configuredDriver(),
            'version' => $chosen?->version(),
            'last_success_at' => $last?->generated_at?->toIso8601String(),
            'error' => $chosen === null ? 'CAD converter is not configured' : null,
        ];
    }

    /**
     * @return list<CadPreviewConverterInterface>
     */
    protected function candidates(): array
    {
        return match ($this->configuredDriver()) {
            'oda' => [$this->oda],
            'libredwg' => [$this->libre],
            default => [$this->oda, $this->libre],
        };
    }

    private function configuredDriver(): string
    {
        $driver = strtolower((string) config('previews.cad.driver', 'auto'));

        return in_array($driver, ['auto', 'oda', 'libredwg'], true) ? $driver : 'auto';
    }
}
