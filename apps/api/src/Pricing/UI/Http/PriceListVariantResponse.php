<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http;

use App\Pricing\Application\Query\PriceListVariant;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A variant of a product with its price.', required: ['variantId', 'label', 'price'])]
final readonly class PriceListVariantResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $variantId,
        #[OA\Property(description: 'The values of the variant joined by ", "; empty for the default variant of a product without variant axes.', example: 'oprawa twarda')]
        public string $label,
        #[OA\Property(description: 'The stored price, null when the variant has no price yet.', nullable: true, allOf: [new OA\Schema(ref: new Model(type: ListedPriceResponse::class))])]
        public ?ListedPriceResponse $price,
    ) {
    }

    public static function of(PriceListVariant $variant): self
    {
        return new self($variant->variantId, $variant->label, null === $variant->price ? null : ListedPriceResponse::of($variant->price));
    }
}
