<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * The page of one product for the store, found by its slug; the answer is ProductDetails or null.
 */
final readonly class GetProductBySlug
{
    public function __construct(public string $slug)
    {
    }
}
