<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Query\ProductCategoryRef;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A category of a product.', required: ['id', 'name'])]
final readonly class ProductCategoryResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(example: 'Obuwie')]
        public string $name,
    ) {
    }

    public static function of(ProductCategoryRef $category): self
    {
        return new self($category->id, $category->name);
    }
}
