<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * One batch of all variants with their keys and the tax classes of their products, ordered by variant id. The next
 * batch starts after the last variant id of this one; an empty batch is the end.
 */
final readonly class ListVariantIdentities
{
    public const int MAX_LIMIT = 1000;

    /**
     * @param string|null $after the last variant id of the previous batch, null for the first one
     * @param int         $limit 1 to 1000
     */
    public function __construct(
        public ?string $after,
        public int $limit,
    ) {
    }
}
