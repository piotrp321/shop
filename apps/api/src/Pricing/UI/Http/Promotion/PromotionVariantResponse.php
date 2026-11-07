<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\Promotion;

use App\Pricing\Application\Query\PromotionVariantView;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A variant of a promotion with its prices, all computed by the API. A variant deleted from the catalog has no product, name or label.', required: ['variantId', 'productId', 'productName', 'label', 'regularGross', 'promotionalGross'])]
final readonly class PromotionVariantResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $variantId,
        #[OA\Property(format: 'uuid', description: 'Null for a variant deleted from the catalog.', nullable: true)]
        public ?string $productId,
        #[OA\Property(description: 'Null for a variant deleted from the catalog.', nullable: true, example: 'Namiot Tarvik 2')]
        public ?string $productName,
        #[OA\Property(description: 'Empty for the default variant, null for a variant deleted from the catalog.', nullable: true, example: 'Zielony')]
        public ?string $label,
        #[OA\Property(description: 'The current catalog gross in grosze; null without a current price.', nullable: true, example: 5999)]
        public ?int $regularGross,
        #[OA\Property(description: 'The gross in the promotion in grosze; null without a catalog price and for an ended promotion (what was paid is in the orders).', nullable: true, example: 4800)]
        public ?int $promotionalGross,
    ) {
    }

    public static function of(PromotionVariantView $variant): self
    {
        return new self($variant->variantId, $variant->productId, $variant->productName, $variant->label, $variant->regularGross, $variant->promotionalGross);
    }
}
