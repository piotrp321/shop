<?php

declare(strict_types=1);

namespace App\Cart\UI\Http;

use App\Cart\Application\View\VariantOfferView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'The offer of one variant of a product. Never the number of units.', required: ['variantId', 'label', 'gross', 'regularGross', 'promotion', 'level', 'purchasable'])]
final readonly class VariantOfferResponse
{
    public function __construct(
        #[OA\Property(description: 'Variant id (UUID): the key of a cart line.', format: 'uuid')]
        public string $variantId,
        #[OA\Property(description: 'The values of the variant joined with ", "; empty for the default variant of a product without axes.', example: 'czarne, 42')]
        public string $label,
        #[OA\Property(description: 'The current gross price in grosze, the price to pay: in a promotion the promotional price; null without a current price.', nullable: true, example: 30000)]
        public ?int $gross,
        #[OA\Property(description: 'The catalog gross price in grosze (equal to `gross` outside a promotion); null without a current price.', nullable: true, example: 37500)]
        public ?int $regularGross,
        #[OA\Property(description: 'The promotion that gives `gross`; null outside a promotion.', ref: new Model(type: VariantOfferPromotionResponse::class), nullable: true)]
        public ?VariantOfferPromotionResponse $promotion,
        #[OA\Property(description: 'Availability level computed by the API.', enum: ['in_stock', 'low_stock', 'out_of_stock'], example: 'in_stock')]
        public string $level,
        #[OA\Property(description: 'Whether one unit can be bought now: a current price and an available unit. The tile offers only such a variant.')]
        public bool $purchasable,
    ) {
    }

    public static function of(VariantOfferView $view): self
    {
        return new self(
            $view->variantId,
            $view->label,
            $view->gross,
            $view->regularGross,
            null === $view->promotion ? null : VariantOfferPromotionResponse::of($view->promotion),
            $view->level,
            $view->purchasable,
        );
    }
}
