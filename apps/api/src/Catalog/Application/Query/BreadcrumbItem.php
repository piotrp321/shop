<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class BreadcrumbItem
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
