<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

final readonly class CreateProduct
{
    /**
     * @param list<string>                                  $additionalCategoryIds
     * @param list<array{key: string, alt: string}>         $images                in position order
     * @param list<string>                                  $variantAxes           0 to 2 axis names, e.g. ["Kolor", "Rozmiar"]
     * @param list<array{id: string, values: list<string>}> $variants              in presentation order; one value per axis
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
