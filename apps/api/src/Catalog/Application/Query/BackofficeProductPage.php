<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Shared\Domain\BoundedCount;

final readonly class BackofficeProductPage
{
    /**
     * @param list<BackofficeProduct> $items
     * @param string|null             $next  opaque cursor of the next page, null on the last page
     * @param BoundedCount|null       $total how many products match the filters; only on the first page (no cursor), else null
     */
    public function __construct(
        public array $items,
        public ?string $next,
        public ?BoundedCount $total = null,
    ) {
    }
}
