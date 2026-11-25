<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Domain\Product\ProductStatus;

final readonly class CreateProduct
{
    /**
     * @param string                                                                  $slug                  empty: derived from the name
     * @param list<array{name: string, value: string}>                                $parameters            in presentation order
     * @param list<string>                                                            $additionalCategoryIds
     * @param list<array{key: string, alt: string}>                                   $images                in position order
     * @param list<string>                                                            $variantAxes           0 to 2 axis names, e.g. ["Kolor", "Rozmiar"]
     * @param list<array{id: string, values: list<string>, sku: string, ean: string}> $variants              in presentation order; one value per axis; the codes as written, an empty text is no code
     * @param ProductStatus                                                           $status                a draft from the panel, published from the seed
     * @param string|null                                                             $actorId               the staff member (`sub`); null for the seed
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $shortDescription,
        public string $brand,
        public string $description,
        public array $parameters,
        public string $mainCategoryId,
        public array $additionalCategoryIds,
        public string $taxClass,
        public array $images,
        public array $variantAxes,
        public array $variants,
        public ProductStatus $status,
        public ?string $actorId,
    ) {
    }
}
