<?php

namespace App\Services\Files\Cad;

/**
 * One local DWG converter. The executable and every path are chosen by the server.
 */
interface CadPreviewConverterInterface
{
    public function key(): string;

    public function available(): bool;

    public function version(): ?string;

    public function convert(string $sourceAbsolute, string $directory): CadPreviewResult;
}
