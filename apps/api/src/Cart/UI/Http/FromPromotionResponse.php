<?php

declare(strict_types=1);

namespace App\Cart\UI\Http;

use App\Cart\Application\View\FromPromotionView;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'The promotion of the variant that gives the "from" price of a product.', required: ['lowestPriorGross'])]
final readonly class FromPromotionResponse
{
    public function __construct(
        #[OA\Property(description: 'The lowest gross price in grosze of the 30 days before the start of the promotion (the Omnibus price); null when the variant had no price before the start.', nullable: true, example: 29900)]
        public ?int $lowestPriorGross,
    ) {
    }

    public static function of(FromPromotionView $view): self
    {
        return new self($view->lowestPriorGross);
    }
}
