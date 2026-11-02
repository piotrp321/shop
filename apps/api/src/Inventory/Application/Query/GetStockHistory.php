<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

/**
 * One page of the stock history of a product, newest entry first.
 */
final readonly class GetStockHistory
{
    public const int DEFAULT_PER_PAGE = 25;
    public const int MAX_PER_PAGE = 100;

    /**
     * @param string      $productId a product id (UUID)
     * @param string|null $after     the `next` of the previous page
     * @param int         $perPage   1 to 100
     */
    public function __construct(
        public string $productId,
        public ?string $after,
        public int $perPage,
    ) {
    }
}
