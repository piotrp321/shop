<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Image;

use App\Shared\Domain\Exception\InvalidInput;

final class InvalidImage extends \DomainException implements InvalidInput
{
    public static function tooLarge(): self
    {
        return new self('Plik jest za duży.');
    }

    public static function unsupportedType(): self
    {
        return new self('Nieobsługiwany typ pliku. Wyślij zdjęcie JPEG, PNG, WebP, AVIF albo HEIC.');
    }

    public static function unreadableDimensions(): self
    {
        return new self('Nie można odczytać wymiarów zdjęcia.');
    }

    public static function tooManyPixels(): self
    {
        return new self('Zdjęcie ma za dużą rozdzielczość (najwyżej 50 megapikseli).');
    }
}
