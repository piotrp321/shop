<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * A category of a product write does not exist. An unknown category in a write is invalid input (a 422 at the field),
 * not a missing resource.
 */
final class UnknownCategory extends \DomainException implements InvalidInput
{
    /**
     * @param int|null $index the position among the additional categories; null for the main category
     */
    public function __construct(public readonly ?int $index)
    {
        parent::__construct('Kategoria nie istnieje.');
    }
}
