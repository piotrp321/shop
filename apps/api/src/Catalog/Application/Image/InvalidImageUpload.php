<?php

declare(strict_types=1);

namespace App\Catalog\Application\Image;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * The request of an image upload carries no usable file (before the file itself is looked at). The messages are shown
 * to the staff member as they are.
 */
final class InvalidImageUpload extends \DomainException implements InvalidInput
{
    public const string TOO_LARGE = 'Plik jest za duży.';

    public static function missingFile(): self
    {
        return new self('Wybierz plik ze zdjęciem.');
    }

    public static function tooLarge(): self
    {
        return new self(self::TOO_LARGE);
    }

    public static function notReceived(): self
    {
        return new self('Nie udało się odebrać pliku. Spróbuj ponownie.');
    }
}
