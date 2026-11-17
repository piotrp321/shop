<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Shared\Domain\BoundedCount;

final readonly class BackofficeCustomerList
{
    /**
     * @param list<BackofficeCustomerListItem> $items
     * @param BoundedCount|null                $total on the first page only
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public ?BoundedCount $total,
    ) {
    }
}
