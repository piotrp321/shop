<?php

declare(strict_types=1);

namespace App\Inventory\UI\Http;

use App\Inventory\Application\Query\StockListItem;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A product with its variants and their stock.', required: ['productId', 'name', 'variants'])]
final readonly class StockListItemResponse
{
    /**
     * @param list<StockListVariantResponse> $variants
     */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $productId,
        #[OA\Property(example: 'Bidon 750 ml')]
        public string $name,
        #[OA\Property(description: 'The variants of the product in presentation order; one variant with an empty label for a product without variant axes.', type: 'array', items: new OA\Items(ref: new Model(type: StockListVariantResponse::class)))]
        public array $variants,
    ) {
    }

    public static function of(StockListItem $item): self
    {
        return new self(
            $item->productId,
            $item->name,
            array_map(StockListVariantResponse::of(...), $item->variants),
        );
    }
}
