<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Image;

use App\Shared\Domain\Exception\InvalidInput;

final class InvalidImage extends \DomainException implements InvalidInput
{
    public static function tooLarge(): self
    {
        return new self('The image file is too large.');
    }

    public static function unsupportedType(): self
    {
        return new self('The image type is not supported.');
    }

    public static function unreadableDimensions(): self
    {
        return new self('The image dimensions cannot be read.');
    }

    public static function tooManyPixels(): self
    {
        return new self('The image resolution is too high.');
    }
}
