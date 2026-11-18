<?php

declare(strict_types=1);

namespace App\DemoData\Photo;

/**
 * A file of the photo pool, as the manifest describes it. The sum is the SHA-256 of the file (the archive is only
 * transport). Every file is a photo of Wikimedia Commons (sourceId, commons:<page id>) in one of its variants (the
 * original, the mirror image or a crop): the pair of both is unique, and the variants of a photo share its source page,
 * author and licence.
 */
final readonly class PhotoEntry
{
    public function __construct(
        public string $file,
        public string $sha256,
        public string $group,
        public string $alt,
        public string $sourceId,
        public string $variant,
        public string $source,
        public string $author,
        public string $license,
    ) {
    }
}
