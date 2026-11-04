<?php

declare(strict_types=1);

namespace App\Cart\UI\Http;

use App\Cart\Application\View\ProductOfferView;
use App\Cart\Application\View\VariantOfferView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'The offer of a product of the list, computed by the API from its purchasable variants (a current price and an available unit). Never the number of units.',
    required: ['productId', 'variantAxes', 'fromGross', 'hasPriceRange', 'currency', 'level', 'variants'],
)]
final readonly class ProductOfferResponse
{
    /**
     * @param list<string>               $variantAxes
     * @param list<VariantOfferResponse> $variants
     */
    public function __construct(
        #[OA\Property(description: 'Product id (UUID).', format: 'uuid')]
        public string $productId,
        #[OA\Property(description: 'Names of the variant axes in order (e.g. "Kolor, Rozmiar"); empty for a product with the default variant only, and for a product unknown to Catalog.', type: 'array', items: new OA\Items(type: 'string'), example: ['Kolor', 'Rozmiar'])]
        public array $variantAxes,
        #[OA\Property(description: 'The lowest gross price in grosze among the purchasable variants; null when no variant can be bought.', nullable: true, example: 30000)]
        public ?int $fromGross,
        #[OA\Property(description: 'Whether the purchasable variants have different prices (the tile shows "od").')]
        public bool $hasPriceRange,
        #[OA\Property(description: 'ISO 4217 code.', enum: ['PLN'])]
        public string $currency,
        #[OA\Property(description: 'The best level among the purchasable variants; out_of_stock when no variant can be bought.', enum: ['in_stock', 'low_stock', 'out_of_stock'], example: 'in_stock')]
        public string $level,
        #[OA\Property(description: 'Every variant in presentation order; none for a product unknown to Catalog.', type: 'array', items: new OA\Items(ref: new Model(type: VariantOfferResponse::class)))]
        public array $variants,
    ) {
    }

    public static function of(ProductOfferView $view): self
    {
        return new self(
            $view->productId,
            $view->variantAxes,
            $view->fromGross,
            $view->hasPriceRange,
            $view->currency,
            $view->level,
            array_map(static fn (VariantOfferView $variant): VariantOfferResponse => VariantOfferResponse::of($variant), $view->variants),
        );
    }
}
