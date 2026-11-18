<?php

declare(strict_types=1);

namespace App\DemoData\Photo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What a photo has to meet to be stored as an original: the rules of the upload in Catalog
 * (FlysystemProductImageStorage; the limits are the same parameters, a contract test compares the verdicts): the size,
 * the type by the contents, readable dimensions and the number of pixels. The reason of a refusal never holds the contents.
 */
final readonly class PhotoRules
{
    public function __construct(
        #[Autowire('%app.image_upload.max_bytes%')]
        public int $maxBytes,
        #[Autowire('%app.image_upload.max_pixels%')]
        public int $maxPixels,
    ) {
    }

    /**
     * @return string|null why the photo is refused, null when it passes
     */
    public function violationOfContents(string $contents): ?string
    {
        if (\strlen($contents) > $this->maxBytes) {
            return \sprintf('larger than %d bytes', $this->maxBytes);
        }
        if (null === ImageKeys::typeOf($contents)) {
            return 'not an image type the catalog accepts (JPEG, PNG, WebP, AVIF or HEIC)';
        }
        // The dimensions come from the header, the image is not decoded.
        $dimensions = @getimagesizefromstring($contents);
        if (false === $dimensions || $dimensions[0] < 1 || $dimensions[1] < 1) {
            return 'the dimensions cannot be read';
        }
        if ($dimensions[0] * $dimensions[1] > $this->maxPixels) {
            return \sprintf('more than %d pixels', $this->maxPixels);
        }

        return null;
    }

    /**
     * The size is checked before the file is read, so an oversized file is not loaded.
     *
     * @return string|null why the photo is refused, null when it passes
     */
    public function violationOfFile(string $path): ?string
    {
        $size = is_file($path) ? @filesize($path) : false;
        if (false === $size) {
            return 'the file cannot be read';
        }
        if ($size > $this->maxBytes) {
            return \sprintf('larger than %d bytes', $this->maxBytes);
        }
        $contents = @file_get_contents($path);
        if (false === $contents) {
            return 'the file cannot be read';
        }

        return $this->violationOfContents($contents);
    }
}
