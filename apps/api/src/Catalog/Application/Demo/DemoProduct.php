<?php

declare(strict_types=1);

namespace App\Catalog\Application\Demo;

final readonly class DemoProduct
{
    /**
     * @param list<string>                                  $additionalCategoryIds
     * @param list<array{file: string, alt: string}>        $images                empty for a product without photos
     * @param list<string>                                  $variantAxes           names of the variant axes, none for a product with the default variant
     * @param list<array{id: string, values: list<string>}> $variants              in presentation order
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $shortDescription,
        public string $mainCategoryId,
        public array $additionalCategoryIds,
        public string $taxClass,
        public array $images,
        public array $variantAxes,
        public array $variants,
    ) {
    }
}
