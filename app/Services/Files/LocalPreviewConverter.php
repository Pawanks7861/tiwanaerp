<?php

namespace App\Services\Files;

use Symfony\Component\Process\Process;

/**
 * LibreOffice and LibreDWG, when they are already installed. The process arguments are a fixed
 * binary plus paths this class created. The original upload name is never part of the command.
 */
class LocalPreviewConverter implements PreviewConverter
{
    public function officeAvailable(): bool
    {
        return $this->officeBinary() !== null;
    }

    public function cadAvailable(): bool
    {
        return $this->cadBinary() !== null;
    }

    public function toPdf(string $sourceAbsolute, string $directory): string
    {
        $binary = $this->officeBinary();
        if ($binary === null) {
            throw new PreviewConversionException(PreviewConversionException::UNAVAILABLE);
        }

        $process = new Process([
            $binary,
            '--headless',
            '--norestore',
            '--nolockcheck',
            '--convert-to',
            'pdf',
            '--outdir',
            $directory,
            $sourceAbsolute,
        ]);
        $process->setTimeout(120);
        $process->run();

        $produced = $directory.DIRECTORY_SEPARATOR.pathinfo($sourceAbsolute, PATHINFO_FILENAME).'.pdf';
        $target = $directory.DIRECTORY_SEPARATOR.'preview.pdf';
        if (is_file($produced) && $produced !== $target) {
            rename($produced, $target);
        }

        if (! is_file($target)) {
            throw new PreviewConversionException(PreviewConversionException::FAILED);
        }

        return $target;
    }

    public function toSvg(string $sourceAbsolute, string $directory): string
    {
        $binary = $this->cadBinary();
        if ($binary === null) {
            throw new PreviewConversionException(PreviewConversionException::UNAVAILABLE);
        }

        $target = $directory.DIRECTORY_SEPARATOR.'preview.svg';
        $process = new Process([$binary, '-o', $target, $sourceAbsolute]);
        $process->setTimeout(120);
        $process->run();

        if (! is_file($target)) {
            throw new PreviewConversionException(PreviewConversionException::FAILED);
        }

        return $target;
    }

    /**
     * Basename used for the staged copy. Only a short allow-listed extension is accepted,
     * so a name such as `a"; rm ../.dwg` cannot change the path.
     */
    public function stagedBasename(string $extension): string
    {
        return 'input.'.$this->guardedExtension($extension);
    }

    public function guardedExtension(string $extension): string
    {
        $extension = strtolower($extension);
        if (! in_array($extension, ['dwg', 'dxf', 'doc', 'docx', 'ppt', 'pptx'], true)) {
            throw new PreviewConversionException(PreviewConversionException::UNSUPPORTED);
        }

        return $extension;
    }

    private function officeBinary(): ?string
    {
        return $this->find(['soffice', 'soffice.exe', 'libreoffice', 'libreoffice.exe']);
    }

    private function cadBinary(): ?string
    {
        return $this->find(['dwg2SVG', 'dwg2SVG.exe']);
    }

    /**
     * @param  list<string>  $names
     */
    private function find(array $names): ?string
    {
        $directories = array_filter(explode(PATH_SEPARATOR, (string) getenv('PATH')));
        foreach ($directories as $directory) {
            foreach ($names as $name) {
                $candidate = $directory.DIRECTORY_SEPARATOR.$name;
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
