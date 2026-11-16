<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

final readonly class ReviseCategory
{
    public function __construct(
        public string $categoryId,
        public int $expectedVersion,
        public string $name,
        public string $slug,
    ) {
    }
}
