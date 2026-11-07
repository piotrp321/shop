<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Query\ProductEditVariant;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A variant of a product in the edit view.', required: ['variantId', 'values'])]
final readonly class ProductEditVariantResponse
{
    /**
     * @param list<string> $values
     */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $variantId,
        #[OA\Property(description: 'One value per axis, in the order of the axes; empty for the default variant of a product without axes.', type: 'array', items: new OA\Items(type: 'string'), example: ['czarne', '42'])]
        public array $values,
    ) {
    }

    public static function of(ProductEditVariant $variant): self
    {
        return new self($variant->variantId, $variant->values);
    }
}
