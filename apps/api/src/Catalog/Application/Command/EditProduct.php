<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * A save of the product form of the panel: the whole data of the product, based on the version shown.
 */
final readonly class EditProduct
{
    /**
     * @param int                                                       $expectedVersion       the version the change is based on
     * @param string                                                    $actorId               the staff member (`sub`)
     * @param string                                                    $slug                  empty: derived from the name, or kept once the slug is locked
     * @param list<string>                                              $additionalCategoryIds
     * @param list<array{key: string, alt: string}>                     $images                in position order
     * @param list<string>                                              $variantAxes           0 to 2 axis names
     * @param list<array{variantId: string|null, values: list<string>}> $variants              in presentation order; a row without an id is a new variant
     */
    public function __construct(
        public string $productId,
        public int $expectedVersion,
        public string $actorId,
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
