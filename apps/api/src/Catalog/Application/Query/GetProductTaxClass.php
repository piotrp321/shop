<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * The tax class code of a product, or null when the product does not exist.
 */
final readonly class GetProductTaxClass
{
    public function __construct(public string $productId)
    {
    }
}
