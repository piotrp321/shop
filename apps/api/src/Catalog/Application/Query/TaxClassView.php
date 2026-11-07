<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class TaxClassView
{
    public function __construct(
        public string $code,
        public string $name,
    ) {
    }
}
