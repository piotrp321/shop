<?php

declare(strict_types=1);

namespace App\Catalog\Application\Demo;

final readonly class DemoCategory
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $parentId,
        public string $slugPath,
    ) {
    }
}
