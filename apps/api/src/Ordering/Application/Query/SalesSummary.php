<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

/**
 * The sales of the dashboard: today (compared with yesterday by the hours of the Polish clock), the last 7 and the last
 * 30 days (compared with as many hours before them), all of completed hours up to `dataUntil`, the start of the
 * current hour.
 */
final readonly class SalesSummary
{
    public function __construct(
        public \DateTimeImmutable $dataUntil,
        public string $currency,
        public PeriodSales $today,
        public PeriodSales $last7Days,
        public PeriodSales $last30Days,
    ) {
    }
}
