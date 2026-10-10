<?php

namespace App\Services\Files\Cad;

use Symfony\Component\Process\Process;

/**
 * Resolves a configured local executable. A bare name is searched on PATH.
 * An absolute path is used only when that file exists. Nothing here is shown to users.
 */
class CadBinary
{
    public function resolve(?string $configured, array $fallbackNames = []): ?string
    {
        $configured = is_string($configured) ? trim($configured) : '';
        if ($configured === '' || $this->rejected($configured)) {
            return $this->search($fallbackNames);
        }

        if ($this->absolute($configured)) {
            return is_file($configured) ? $configured : null;
        }

        return $this->search([$configured, ...$fallbackNames]);
    }

    public function version(?string $binary): ?string
    {
        if ($binary === null || ! is_file($binary)) {
            return null;
        }

        try {
            $process = new Process([$binary, '--version']);
            $process->setTimeout(5);
            $process->run();
            $line = trim(strtok($process->getOutput()."\n".$process->getErrorOutput(), "\n") ?: '');
        } catch (\Throwable) {
            return null;
        }

        $line = preg_replace('/[A-Za-z]:\\\\[^\s]+/', '', $line) ?? '';
        $line = preg_replace('#/(?:usr|opt|home|var|tmp|bin)/[^\s]+#', '', $line) ?? '';
        $line = trim($line);

        return $line === '' ? null : mb_substr($line, 0, 80);
    }

    /**
     * @param  list<string>  $names
     */
    private function search(array $names): ?string
    {
        $directories = array_filter(explode(PATH_SEPARATOR, (string) getenv('PATH')));
        foreach ($directories as $directory) {
            foreach ($names as $name) {
                if ($name === '' || $this->rejected($name) || $this->absolute($name)) {
                    continue;
                }
                $candidate = $directory.DIRECTORY_SEPARATOR.$name;
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function absolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    private function rejected(string $path): bool
    {
        return str_contains($path, "\0")
            || str_contains($path, "\n")
            || str_contains($path, "\r")
            || preg_match('#^(https?|ftp)://#i', $path) === 1;
    }
}
