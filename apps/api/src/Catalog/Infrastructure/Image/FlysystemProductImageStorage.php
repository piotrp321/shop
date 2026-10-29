<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Image;

use App\Catalog\Application\Port\ProductImageStorage;
use App\Catalog\Domain\Product\OriginalImageType;
use League\Flysystem\FilesystemOperator;

final readonly class FlysystemProductImageStorage implements ProductImageStorage
{
    private const array MIME_TYPES = [
        'image/jpeg' => OriginalImageType::Jpg,
        'image/png' => OriginalImageType::Png,
        'image/webp' => OriginalImageType::Webp,
        'image/avif' => OriginalImageType::Avif,
        'image/heic' => OriginalImageType::Heic,
    ];

    public function __construct(
        private FilesystemOperator $filesystem,
        private int $maxBytes,
        private int $maxPixels,
    ) {
    }

    public function store(string $contents): string
    {
        if (\strlen($contents) > $this->maxBytes) {
            throw InvalidImage::tooLarge();
        }

        $mime = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($contents);
        $type = false === $mime ? null : (self::MIME_TYPES[$mime] ?? null);
        if (null === $type) {
            throw InvalidImage::unsupportedType();
        }

        // Dimensions come from the header, the image is not decoded.
        $dimensions = @getimagesizefromstring($contents);
        if (false === $dimensions || $dimensions[0] < 1 || $dimensions[1] < 1) {
            throw InvalidImage::unreadableDimensions();
        }
        if ($dimensions[0] * $dimensions[1] > $this->maxPixels) {
            throw InvalidImage::tooManyPixels();
        }

        $key = \sprintf('originals/%s.%s', hash('sha256', $contents), $type->value);
        $this->filesystem->write($key, $contents);

        return $key;
    }

    public function purge(): void
    {
        $this->filesystem->deleteDirectory('originals');
    }
}
