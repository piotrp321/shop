<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

final readonly class CreateCategory
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $parentId,
    ) {
    }
}
