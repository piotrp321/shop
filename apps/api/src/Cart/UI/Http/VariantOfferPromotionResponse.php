<?php

declare(strict_types=1);

namespace App\Cart\UI\Http;

use App\Cart\Application\View\VariantPromotionView;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'The promotion that gives the price of a variant now.', required: ['endsAt', 'lowestPriorGross'])]
final readonly class VariantOfferPromotionResponse
{
    public function __construct(
        #[OA\Property(description: 'The end of the promotion in UTC (RFC 3339, whole seconds); the promotion lasts until before it.', format: 'date-time', example: '2026-11-20T23:00:00+00:00')]
        public string $endsAt,
        #[OA\Property(description: 'The lowest gross price in grosze of the 30 days before the start of the promotion (the Omnibus price); null when the variant had no price before the start.', nullable: true, example: 29900)]
        public ?int $lowestPriorGross,
    ) {
    }

    public static function of(VariantPromotionView $view): self
    {
        return new self($view->endsAt->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM), $view->lowestPriorGross);
    }
}
