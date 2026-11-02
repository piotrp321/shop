<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

final readonly class PriceList
{
    /**
     * @param list<PriceListItem> $items
     * @param string|null         $next  cursor of the next page, null on the last page
     */
    public function __construct(
        public array $items,
        public ?string $next,
    ) {
    }
}
