<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * A variant row of a product write names a variant id the product does not have.
 */
final class UnknownVariant extends \DomainException implements InvalidInput
{
    /**
     * @param int $index the position of the row in the written variants
     */
    public function __construct(public readonly int $index)
    {
        parent::__construct('Wariant nie należy do tego produktu.');
    }
}
