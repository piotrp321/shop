<?php

declare(strict_types=1);

namespace App\Catalog\Application\Demo;

final readonly class DemoProduct
{
    /**
     * @param list<string>                           $additionalCategoryIds
     * @param list<array{file: string, alt: string}> $images                empty for a product without photos
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
