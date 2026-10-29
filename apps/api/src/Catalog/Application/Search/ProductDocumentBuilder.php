<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

final readonly class ProductDocumentBuilder
{
    public function build(ProductSearchSnapshot $snapshot): ProductDocument
    {
        // Each path runs from the root to the category inclusive, so their union is every category of the
        // product plus all its ancestors; paths of categories in one subtree share their prefix.
        $ancestorIds = array_values(array_unique(array_merge(...$snapshot->categoryPaths)));

        return new ProductDocument(
            $snapshot->id,
            $snapshot->version,
            $snapshot->name,
            $snapshot->slug,
            $snapshot->shortDescription,
            $snapshot->mainCategoryId,
            [$snapshot->mainCategoryId, ...$snapshot->additionalCategoryIds],
            $ancestorIds,
            $snapshot->mainImage,
        );
    }
}
