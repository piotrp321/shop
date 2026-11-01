<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http;

use App\Pricing\Application\Query\PriceHistoryItem;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'One recorded price change: the price as it was set. Amounts are in grosze, the rate in basis points; all computed by the API.', required: ['changedAt', 'gross', 'net', 'vat', 'vatRate', 'taxClass', 'source', 'actorName'])]
final readonly class PriceHistoryItemResponse
{
    public function __construct(
        #[OA\Property(description: 'When the price was changed (RFC 3339, UTC).', example: '2026-11-01T12:30:00+00:00')]
        public string $changedAt,
        #[OA\Property(description: 'Gross price (VAT included) in grosze.', example: 5490)]
        public int $gross,
        #[OA\Property(description: 'Net price in grosze.', example: 5229)]
        public int $net,
        #[OA\Property(description: 'VAT amount in grosze (gross minus net).', example: 261)]
        public int $vat,
        #[OA\Property(description: 'The VAT rate the amounts were computed at, in basis points (2300 = 23%).', example: 500)]
        public int $vatRate,
        #[OA\Property(description: 'The tax class of the price at the time.', example: 'printed_book')]
        public string $taxClass,
        #[OA\Property(description: 'What made the change: `staff` (a staff member in the panel), `seed` (the demo data) or `tax_reconciliation` (a repair after a rate change).', enum: ['staff', 'seed', 'tax_reconciliation'], example: 'staff')]
        public string $source,
        #[OA\Property(description: 'The staff member who made a `staff` change, null for the other sources.', nullable: true, example: 'Anna Admin')]
        public ?string $actorName,
    ) {
    }

    public static function of(PriceHistoryItem $item): self
    {
        return new self(
            $item->changedAt->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            $item->gross,
            $item->net,
            $item->vat,
            $item->vatRate,
            $item->taxClass,
            $item->source,
            $item->actorName,
        );
    }
}
