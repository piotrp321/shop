<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Shared\Domain\BoundedCount;

final readonly class PromotionList
{
    /**
     * @param list<PromotionListItem> $items
     * @param string|null             $next  the cursor of the next page, null on the last one
     * @param BoundedCount|null       $total how many promotions match the filter; only on the first page, else null
     */
    public function __construct(
        public array $items,
        public ?string $next,
        public ?BoundedCount $total,
    ) {
    }
}
