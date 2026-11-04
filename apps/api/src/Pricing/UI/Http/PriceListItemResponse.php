<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http;

use App\Pricing\Application\Query\PriceListItem;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A product with its variants and their prices.', required: ['productId', 'name', 'taxClass', 'applicableVatRate', 'variants'])]
final readonly class PriceListItemResponse
{
    /**
     * @param list<PriceListVariantResponse> $variants
     */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $productId,
        #[OA\Property(example: 'Przewodnik Tatry Zachodnie')]
        public string $name,
        #[OA\Property(description: 'The tax class of the product.', example: 'printed_book')]
        public string $taxClass,
        #[OA\Property(description: 'The VAT rate of the tax class today, in basis points (2300 = 23%).', example: 500)]
        public int $applicableVatRate,
        #[OA\Property(description: 'The variants of the product in presentation order; one variant with an empty label for a product without variant axes.', type: 'array', items: new OA\Items(ref: new Model(type: PriceListVariantResponse::class)))]
        public array $variants,
    ) {
    }

    public static function of(PriceListItem $item): self
    {
        return new self(
            $item->productId,
            $item->name,
            $item->taxClass,
            $item->applicableVatRate,
            array_map(PriceListVariantResponse::of(...), $item->variants),
        );
    }
}
