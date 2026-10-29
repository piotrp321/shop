<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

final readonly class CreateProduct
{
    /**
     * @param list<string>                          $additionalCategoryIds
     * @param list<array{key: string, alt: string}> $images                in position order
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $shortDescription,
        public string $mainCategoryId,
        public array $additionalCategoryIds,
        public string $taxCategory,
        public array $images,
    ) {
    }
}
