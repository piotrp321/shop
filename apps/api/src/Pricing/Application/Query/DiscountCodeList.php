<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

final readonly class DiscountCodeList
{
    /**
     * @param list<DiscountCodeView> $items
     * @param string|null            $next  the cursor of the next page, null on the last one
     */
    public function __construct(
        public array $items,
        public ?string $next,
    ) {
    }
}
