<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * A variant row of a product write names a SKU that a variant of another product has (also a draft, whatever the case
 * the code was written in). The write was refused before anything was saved; the unique index of the table settles the
 * same case when two writes race ({@see SkuAlreadyTaken}).
 */
final class SkuUsedByAnotherProduct extends \DomainException implements InvalidInput
{
    /**
     * @param int $index the position of the row in the written variants
     */
    public function __construct(public readonly int $index)
    {
        parent::__construct('Kod SKU jest już używany przez inny produkt.');
    }
}
