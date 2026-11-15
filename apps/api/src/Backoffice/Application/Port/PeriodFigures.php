<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Port;

/**
 * The sales of one period as Ordering computes them: revenue, number of orders and average (grosze; the average null
 * without orders) and the change of the revenue in basis points (null without revenue before); all null for a period
 * without a completed hour.
 */
final readonly class PeriodFigures
{
    public function __construct(
        public ?int $revenue,
        public ?int $orderCount,
        public ?int $averageOrderValue,
        public ?int $changeBasisPoints,
    ) {
    }
}
