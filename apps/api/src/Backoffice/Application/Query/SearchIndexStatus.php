<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Query;

final readonly class SearchIndexStatus
{
    /**
     * @param ?int  $indexedProducts documents in the index, null when it cannot be counted (then never in sync)
     * @param ?int  $catalogProducts the estimate of the number of products, null while the database has none
     * @param ?bool $inSync          the index is within 1% of the estimate; null without an estimate, false when the index cannot be counted
     */
    public function __construct(
        public ?int $indexedProducts,
        public ?int $catalogProducts,
        public ?bool $inSync,
    ) {
    }
}
