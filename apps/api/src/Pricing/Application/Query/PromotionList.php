<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

final readonly class PromotionList
{
    /**
     * @param list<PromotionListItem> $items
     * @param string|null             $next  the cursor of the next page, null on the last one
     */
    public function __construct(
        public array $items,
        public ?string $next,
    ) {
    }
}
