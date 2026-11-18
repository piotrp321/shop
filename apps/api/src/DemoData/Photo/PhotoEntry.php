<?php

declare(strict_types=1);

namespace App\DemoData\Photo;

/**
 * A file of the photo pool, as the manifest describes it. The sum is the SHA-256 of the file (the archive is only
 * transport).
 */
final readonly class PhotoEntry
{
    public function __construct(
        public string $file,
        public string $sha256,
        public string $group,
        public string $alt,
        public string $unsplashId,
        public string $source,
        public string $author,
        public string $license,
    ) {
    }
}
