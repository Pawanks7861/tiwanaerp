<?php

namespace App\Services\Files\Cad;

use App\Services\Files\PreviewConversionException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Approved local ODA File Converter (or a compatible folder-in, folder-out tool).
 * It is used only when ODA_CONVERTER_BINARY is configured. This class does not download it.
 */
class OdaCadConverter implements CadPreviewConverterInterface
{
    public function __construct(private readonly CadBinary $binaries) {}

    public function key(): string
    {
        return 'oda';
    }

    public function available(): bool
    {
        return $this->binary() !== null;
    }

    public function version(): ?string
    {
        return $this->binaries->version($this->binary());
    }

    public function convert(string $sourceAbsolute, string $directory): CadPreviewResult
    {
        $binary = $this->binary();
        if ($binary === null) {
            throw new PreviewConversionException(PreviewConversionException::UNAVAILABLE);
        }

        $inputDir = $directory.DIRECTORY_SEPARATOR.'oda-in';
        $outputDir = $directory.DIRECTORY_SEPARATOR.'oda-out';
        if ((! is_dir($inputDir) && ! mkdir($inputDir, 0700, true)) || (! is_dir($outputDir) && ! mkdir($outputDir, 0700, true))) {
            throw new PreviewConversionException(PreviewConversionException::FAILED);
        }

        $staged = $inputDir.DIRECTORY_SEPARATOR.'input.dwg';
        if (! $this->copy($sourceAbsolute, $staged)) {
            throw new PreviewConversionException(PreviewConversionException::FAILED);
        }

        $format = strtoupper((string) config('previews.cad.oda_format', 'PDF'));
        if (! in_array($format, ['PDF', 'SVG'], true)) {
            throw new PreviewConversionException(PreviewConversionException::UNSUPPORTED);
        }

        $process = new Process($this->command($binary, $inputDir, $outputDir, $format));
        $process->setTimeout(min(300, max(30, (int) config('previews.cad.timeout', 180))));

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new PreviewConversionException(PreviewConversionException::TIMED_OUT);
        }

        $extension = strtolower($format);
        $produced = $outputDir.DIRECTORY_SEPARATOR.'input.'.$extension;
        if (! is_file($produced)) {
            $found = glob($outputDir.DIRECTORY_SEPARATOR.'*.'.$extension) ?: [];
            $produced = is_string($found[0] ?? null) ? $found[0] : '';
        }

        if ($produced === '' || ! is_file($produced)) {
            throw new PreviewConversionException(PreviewConversionException::FAILED);
        }

        return new CadPreviewResult($produced, $extension);
    }

    /**
     * ODA File Converter: input folder, output folder, version, type, recurse, audit, filter.
     *
     * @return list<string>
     */
    public function command(string $binary, string $inputDir, string $outputDir, string $format): array
    {
        return [
            $binary,
            $inputDir,
            $outputDir,
            (string) config('previews.cad.oda_version', 'ACAD2018'),
            $format,
            '0',
            '1',
            'input.dwg',
        ];
    }

    private function binary(): ?string
    {
        $configured = config('previews.cad.oda_binary');

        return $this->binaries->resolve(is_string($configured) ? $configured : null);
    }

    private function copy(string $from, string $to): bool
    {
        $source = fopen($from, 'rb');
        $target = fopen($to, 'wb');
        if ($source === false || $target === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }

            return false;
        }

        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        return true;
    }
}
