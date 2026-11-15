<?php

declare(strict_types=1);

namespace App\Backoffice\UI\Http;

use App\Backoffice\Application\Port\PeriodFigures;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'The sales of one period. Amounts in grosze. Every field is null for a period without a completed hour (today between 0:00 and 1:00).',
    required: ['revenue', 'orderCount', 'averageOrderValue', 'changeBasisPoints'],
)]
final readonly class DashboardSalesPeriodResponse
{
    public function __construct(
        #[OA\Property(description: 'The gross revenue in grosze.', minimum: 0, nullable: true, example: 1234500)]
        public ?int $revenue,
        #[OA\Property(description: 'The number of orders paid in the period.', minimum: 0, nullable: true, example: 42)]
        public ?int $orderCount,
        #[OA\Property(description: 'Revenue per order in grosze, rounded half up; null without orders.', minimum: 0, nullable: true, example: 29393)]
        public ?int $averageOrderValue,
        #[OA\Property(description: 'The change of the revenue against the period before in basis points (1250 is +12.5%), rounded half away from zero; null when the period before had no revenue.', nullable: true, example: 1250)]
        public ?int $changeBasisPoints,
    ) {
    }

    public static function of(PeriodFigures $period): self
    {
        return new self($period->revenue, $period->orderCount, $period->averageOrderValue, $period->changeBasisPoints);
    }
}
