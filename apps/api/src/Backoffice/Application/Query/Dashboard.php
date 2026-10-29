<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Query;

final readonly class Dashboard
{
    public function __construct(
        public CatalogSection $catalog,
        public ?SystemSection $system,
    ) {
    }
}
