<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Query;

final readonly class SearchIndexStatus
{
    /**
     * @param ?int $indexedProducts documents in the index, null when it cannot be counted (then never in sync)
     */
    public function __construct(
        public ?int $indexedProducts,
        public int $catalogProducts,
        public bool $inSync,
    ) {
    }
}
