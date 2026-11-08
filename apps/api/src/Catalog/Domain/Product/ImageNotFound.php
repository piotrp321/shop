<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * An image of a product write names a key without an original in the image storage.
 */
final class ImageNotFound extends \DomainException implements InvalidInput
{
    /**
     * @param int $index the position of the image in the written images
     */
    public function __construct(public readonly int $index)
    {
        parent::__construct('Nie ma takiego zdjęcia. Wyślij plik ponownie.');
    }
}
