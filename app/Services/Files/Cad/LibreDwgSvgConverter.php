<?php

namespace App\Services\Files\Cad;

use App\Services\Files\PreviewConversionException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * LibreDWG dwg2SVG. The source file is always the staged input.dwg, never the upload name.
 */
class LibreDwgSvgConverter implements CadPreviewConverterInterface
{
    public function __construct(private readonly CadBinary $binaries) {}

    public function key(): string
    {
        return 'libredwg';
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

        $target = $directory.DIRECTORY_SEPARATOR.'preview.svg';
        $handle = fopen($target, 'wb');
        if ($handle === false) {
            throw new PreviewConversionException(PreviewConversionException::FAILED);
        }

        $process = new Process($this->command($binary, $sourceAbsolute));
        $process->setTimeout($this->timeout());

        try {
            $process->run(function (string $type, string $buffer) use ($handle) {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);
                }
            });
        } catch (ProcessTimedOutException) {
            fclose($handle);
            throw new PreviewConversionException(PreviewConversionException::TIMED_OUT);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if (! is_file($target) || filesize($target) === 0) {
            throw new PreviewConversionException(PreviewConversionException::FAILED);
        }

        return new CadPreviewResult($target, 'svg');
    }

    /**
     * LibreDWG 0.13 writes SVG to standard output. The upload name is never an argument.
     *
     * @return list<string>
     */
    public function command(string $binary, string $sourceAbsolute): array
    {
        return [$binary, $sourceAbsolute];
    }

    private function binary(): ?string
    {
        return $this->binaries->resolve((string) config('previews.cad.libredwg_binary'), ['dwg2SVG', 'dwg2SVG.exe']);
    }

    private function timeout(): int
    {
        return min(300, max(30, (int) config('previews.cad.timeout', 180)));
    }
}
