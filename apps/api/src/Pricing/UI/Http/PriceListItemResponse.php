<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http;

use App\Pricing\Application\Query\PriceListItem;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A product with its price.', required: ['productId', 'name', 'taxClass', 'applicableVatRate', 'price'])]
final readonly class PriceListItemResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $productId,
        #[OA\Property(example: 'Przewodnik Tatry Zachodnie')]
        public string $name,
        #[OA\Property(description: 'The tax class of the product.', example: 'printed_book')]
        public string $taxClass,
        #[OA\Property(description: 'The VAT rate of the tax class today, in basis points (2300 = 23%).', example: 500)]
        public int $applicableVatRate,
        #[OA\Property(description: 'The stored price, null when the product has no price yet.', nullable: true, allOf: [new OA\Schema(ref: new Model(type: ListedPriceResponse::class))])]
        public ?ListedPriceResponse $price,
    ) {
    }

    public static function of(PriceListItem $item): self
    {
        return new self(
            $item->productId,
            $item->name,
            $item->taxClass,
            $item->applicableVatRate,
            null === $item->price ? null : ListedPriceResponse::of($item->price),
        );
    }
}
