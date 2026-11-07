<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

/**
 * The data of a product write once the body passed ValidProductWrite: the same values, with the types the validation
 * guarantees.
 */
final readonly class ProductWriteData
{
    /**
     * @param list<string>                                              $additionalCategoryIds
     * @param list<array{key: string, alt: string}>                     $images
     * @param list<string>                                              $variantAxes
     * @param list<array{variantId: string|null, values: list<string>}> $variants
     */
    private function __construct(
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

    public static function of(mixed $name, mixed $slug, mixed $shortDescription, mixed $mainCategoryId, mixed $additionalCategoryIds, mixed $taxClass, mixed $images, mixed $variantAxes, mixed $variants): self
    {
        \assert(\is_string($name) && \is_string($slug) && \is_string($shortDescription) && \is_string($mainCategoryId) && \is_string($taxClass));
        \assert(\is_array($additionalCategoryIds) && \is_array($images) && \is_array($variantAxes) && \is_array($variants));

        /** @var list<string> $additionalCategoryIds */
        /** @var list<array{key: string, alt: string}> $images */
        /** @var list<string> $variantAxes */
        /** @var list<array{variantId: string|null, values: list<string>}> $variants */
        return new self(
            $name,
            $slug,
            $shortDescription,
            $mainCategoryId,
            $additionalCategoryIds,
            $taxClass,
            array_map(static fn (array $image): array => ['key' => $image['key'], 'alt' => $image['alt']], $images),
            $variantAxes,
            array_map(static fn (array $row): array => ['variantId' => $row['variantId'], 'values' => $row['values']], $variants),
        );
    }
}
