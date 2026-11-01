<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * One batch of all products with their tax class, ordered by id. The next batch starts after the last id of this
 * one; an empty batch is the end.
 */
final readonly class ListProductsForPricing
{
    public const int MAX_LIMIT = 1000;

    /**
     * @param string|null $after the last product id of the previous batch, null for the first one
     * @param int         $limit 1 to 1000
     */
    public function __construct(
        public ?string $after,
        public int $limit,
    ) {
    }
}
