<?php

declare(strict_types=1);

namespace App\Inventory\UI\Http;

use App\Inventory\Application\Command\StockView;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'The stock of a product after a receipt or a correction, in whole units; all computed by the API.', required: ['productId', 'physical', 'reserved', 'available', 'level', 'version', 'updatedAt'])]
final readonly class StockStateResponse
{
    public function __construct(
        #[OA\Property(description: 'Product id (UUID).', format: 'uuid')]
        public string $productId,
        #[OA\Property(description: 'Units on the shelf.', example: 12)]
        public int $physical,
        #[OA\Property(description: 'Units promised to active reservations.', example: 2)]
        public int $reserved,
        #[OA\Property(description: 'Physical minus reserved.', example: 10)]
        public int $available,
        #[OA\Property(description: 'Availability level shown in the store.', enum: ['in_stock', 'low_stock', 'out_of_stock'], example: 'in_stock')]
        public string $level,
        #[OA\Property(description: 'Version of the stock, to send with a correction; null when the product has no stock record.', nullable: true, example: 3)]
        public ?int $version,
        #[OA\Property(description: 'When the stock last changed (RFC 3339, UTC); null when the product has no stock record.', nullable: true, example: '2026-11-02T12:30:00+00:00')]
        public ?string $updatedAt,
    ) {
    }

    public static function of(StockView $stock): self
    {
        return new self(
            $stock->productId,
            $stock->physical,
            $stock->reserved,
            $stock->available,
            $stock->level,
            $stock->version,
            $stock->updatedAt?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
        );
    }
}
