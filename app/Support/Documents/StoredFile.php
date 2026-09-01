<?php

declare(strict_types=1);

namespace App\Support\Documents;

/**
 * Metadata for a file that has been written to the private document store (M9).
 */
final class StoredFile
{
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly string $originalFilename,
        public readonly ?string $mimeType,
        public readonly int $sizeBytes,
        public readonly string $checksum,
    ) {}
}
