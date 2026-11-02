<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * Whether a product exists in the catalog.
 */
final readonly class GetProductExists
{
    public function __construct(public string $productId)
    {
    }
}
