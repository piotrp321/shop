<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\Search\ProductSort;

final readonly class ListProducts
{
    /**
     * @param string|null                 $categoryId   filters by the category and all its descendants
     * @param int                         $page         from 1
     * @param int                         $perPage      from 1
     * @param string|null                 $phrase       the trimmed search phrase
     * @param array<string, list<string>> $options      wanted values per axis name (one variant has them all)
     * @param int|null                    $priceMin     gross, in grosze
     * @param int|null                    $priceMax     gross, in grosze
     * @param ProductSort|null            $sort         null: relevance with a phrase, otherwise by name
     */
    public function __construct(
        public ?string $categoryId,
        public int $page,
        public int $perPage,
        public ?string $phrase = null,
        public array $options = [],
        public ?int $priceMin = null,
        public ?int $priceMax = null,
        public bool $availableOnly = false,
        public ?ProductSort $sort = null,
    ) {
    }
}
