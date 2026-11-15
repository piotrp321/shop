<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

final readonly class DeleteCategory
{
    public function __construct(
        public string $categoryId,
        public int $expectedVersion,
    ) {
    }
}
