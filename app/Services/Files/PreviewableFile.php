<?php

namespace App\Services\Files;

/**
 * A stored file the viewer can describe, without exposing its disk path to the client.
 */
final class PreviewableFile
{
    public function __construct(
        public readonly string $source,
        public readonly int $id,
        public readonly int $companyId,
        public readonly string $disk,
        public readonly string $path,
        public readonly string $name,
        public readonly string $extension,
        public readonly int $size,
        public readonly ?string $checksum,
        public readonly ?string $uploadedBy,
        public readonly ?string $uploadedAt,
    ) {}
}
