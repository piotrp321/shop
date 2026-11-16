<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Port;

/**
 * The sales as Ordering sums them: today, the last 7 and the last 30 days of completed hours up to `dataUntil` (the
 * start of the current hour), amounts in grosze of `currency`.
 */
final readonly class SalesFigures
{
    public function __construct(
        public \DateTimeImmutable $dataUntil,
        public string $currency,
        public PeriodFigures $today,
        public PeriodFigures $last7Days,
        public PeriodFigures $last30Days,
    ) {
    }
}
