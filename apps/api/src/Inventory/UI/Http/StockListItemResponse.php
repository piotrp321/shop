<?php

declare(strict_types=1);

namespace App\Inventory\UI\Http;

use App\Inventory\Application\Query\StockListItem;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A product with its stock.', required: ['productId', 'name', 'stock'])]
final readonly class StockListItemResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $productId,
        #[OA\Property(example: 'Bidon 750 ml')]
        public string $name,
        #[OA\Property(description: 'The stored stock, null when the product has no stock record (nothing on the shelf, nothing reserved).', nullable: true, allOf: [new OA\Schema(ref: new Model(type: ListedStockResponse::class))])]
        public ?ListedStockResponse $stock,
    ) {
    }

    public static function of(StockListItem $item): self
    {
        return new self(
            $item->productId,
            $item->name,
            null === $item->stock ? null : ListedStockResponse::of($item->stock),
        );
    }
}
