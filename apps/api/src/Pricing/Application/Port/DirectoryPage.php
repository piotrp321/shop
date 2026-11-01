<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

final readonly class DirectoryPage
{
    /**
     * @param list<DirectoryProduct> $items
     * @param string|null            $next  cursor of the next page, null on the last page
     */
    public function __construct(
        public array $items,
        public ?string $next,
    ) {
    }
}
