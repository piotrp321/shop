<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http;

use App\Pricing\Application\Query\ListedPrice;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'The stored price of a product variant. Amounts are in grosze, the rate in basis points; all computed by the API.')]
final readonly class ListedPriceResponse
{
    public function __construct(
        #[OA\Property(description: 'Gross price (VAT included) in grosze.', example: 5490)]
        public int $gross,
        #[OA\Property(description: 'Net price in grosze.', example: 5229)]
        public int $net,
        #[OA\Property(description: 'VAT amount in grosze (gross minus net).', example: 261)]
        public int $vat,
        #[OA\Property(description: 'The VAT rate the amounts were computed at, in basis points (2300 = 23%).', example: 500)]
        public int $vatRate,
        #[OA\Property(description: 'ISO 4217 code.', example: 'PLN')]
        public string $currency,
        #[OA\Property(description: 'Version of the price, for the optimistic locking of changes.', example: 1)]
        public int $version,
        #[OA\Property(description: 'When the price last changed (RFC 3339).', example: '2026-11-01T12:30:00+00:00')]
        public string $updatedAt,
        #[OA\Property(description: 'The stored rate is no longer the rate of the price\'s tax class: the price is not offered in the store until it is repaired.', example: false)]
        public bool $vatRateOutdated,
    ) {
    }

    public static function of(ListedPrice $price): self
    {
        return new self(
            $price->gross,
            $price->net,
            $price->vat,
            $price->vatRate,
            $price->currency,
            $price->version,
            $price->updatedAt->format(\DATE_ATOM),
            $price->vatRateOutdated,
        );
    }
}
