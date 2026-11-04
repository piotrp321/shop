<?php

declare(strict_types=1);

namespace App\Inventory\UI\Http;

use App\Inventory\Application\Query\StockListVariant;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A variant of a product with its stock.', required: ['variantId', 'label', 'stock'])]
final readonly class StockListVariantResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $variantId,
        #[OA\Property(description: 'The values of the variant joined by ", "; empty for the default variant of a product without variant axes.', example: 'zielony')]
        public string $label,
        #[OA\Property(description: 'The stored stock, null when the variant has no stock record (nothing on the shelf, nothing reserved).', nullable: true, allOf: [new OA\Schema(ref: new Model(type: ListedStockResponse::class))])]
        public ?ListedStockResponse $stock,
    ) {
    }

    public static function of(StockListVariant $variant): self
    {
        return new self($variant->variantId, $variant->label, null === $variant->stock ? null : ListedStockResponse::of($variant->stock));
    }
}
