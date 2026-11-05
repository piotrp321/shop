<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * One page of the price history of a product variant, newest change first.
 */
final readonly class GetPriceHistory
{
    public const int DEFAULT_PER_PAGE = 25;
    public const int MAX_PER_PAGE = 100;

    /**
     * @param string      $variantId a variant id (UUID)
     * @param string|null $after     the `next` of the previous page
     * @param int         $perPage   1 to 100
     */
    public function __construct(
        public string $variantId,
        public ?string $after,
        public int $perPage,
    ) {
    }
}
