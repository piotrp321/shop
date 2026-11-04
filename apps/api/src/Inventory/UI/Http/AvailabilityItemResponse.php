<?php

declare(strict_types=1);

namespace App\Inventory\UI\Http;

use App\Inventory\Application\Query\ProductStockLevel;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'The availability level of one product. Never the number of units.', required: ['productId', 'level'])]
final readonly class AvailabilityItemResponse
{
    public function __construct(
        #[OA\Property(description: 'Product id (UUID).', format: 'uuid')]
        public string $productId,
        #[OA\Property(description: 'Availability level computed by the API: no units available, 1 to 5 (last units), or more.', enum: ['in_stock', 'low_stock', 'out_of_stock'], example: 'in_stock')]
        public string $level,
    ) {
    }

    public static function of(ProductStockLevel $stock): self
    {
        return new self($stock->productId, $stock->level);
    }
}
