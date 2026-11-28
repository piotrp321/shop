<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * Two variants of one product have the same SKU (the capitals of the code are compared).
 */
final class SkuRepeated extends \DomainException implements InvalidInput
{
    /**
     * @param int $index the position of the later of the two rows in the written variants
     */
    public function __construct(public readonly int $index)
    {
        parent::__construct('Ten kod SKU powtarza się w produkcie.');
    }
}
