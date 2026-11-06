<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

final readonly class ProductDocumentBuilder
{
    /**
     * Prices, what can be bought and the "from" price are copied from the offer, never computed here: the rule
     * belongs to the module of the offer, so the index agrees with the tile.
     *
     * @param ProductFacts|null $facts   the offer of the product, null when the offer has none
     * @param int               $version the version of the document in the index
     */
    public function build(ProductSearchSnapshot $snapshot, ?ProductFacts $facts, int $version): ProductDocument
    {
        // Each path runs from the root to the category inclusive, so their union is every category of the
        // product plus all its ancestors; paths of categories in one subtree share their prefix.
        $ancestorIds = array_values(array_unique(array_merge(...$snapshot->categoryPaths)));

        $variants = [];
        foreach ($snapshot->variants as $variant) {
            // A variant the offer does not know has no price and cannot be bought.
            $offer = $facts->variants[$variant['id']] ?? null;
            $variants[] = new VariantDocument(
                $offer?->gross,
                $offer->purchasable ?? false,
                $snapshot->variantAxes,
                array_map(
                    static fn (string $axis, string $value): string => $axis.VariantDocument::OPTION_SEPARATOR.$value,
                    $snapshot->variantAxes,
                    $variant['values'],
                ),
            );
        }

        return new ProductDocument(
            $snapshot->id,
            $version,
            $snapshot->name,
            $snapshot->slug,
            $snapshot->shortDescription,
            $snapshot->mainCategoryId,
            [$snapshot->mainCategoryId, ...$snapshot->additionalCategoryIds],
            $ancestorIds,
            $snapshot->mainImage,
            $snapshot->categoryNames,
            $variants,
            $facts?->fromGross,
        );
    }
}
