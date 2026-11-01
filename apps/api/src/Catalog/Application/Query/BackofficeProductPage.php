<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class BackofficeProductPage
{
    /**
     * @param list<BackofficeProduct> $items
     * @param string|null             $next  opaque cursor of the next page, null on the last page
     */
    public function __construct(
        public array $items,
        public ?string $next,
    ) {
    }
}
