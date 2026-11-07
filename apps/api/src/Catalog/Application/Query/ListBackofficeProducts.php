<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * One page of the product list of the panel, by name, then by id, with keyset paging; the filters and their rules are
 * those of {@see SearchBackofficeProducts}.
 */
final readonly class ListBackofficeProducts
{
    public const int DEFAULT_PER_PAGE = 25;

    /**
     * @param string|null $status `draft` or `published`; null for both
     */
    public function __construct(
        public ?string $term,
        public ?string $categoryId,
        public ?string $status,
        public ?string $after,
        public int $perPage = self::DEFAULT_PER_PAGE,
    ) {
    }
}
