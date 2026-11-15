<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Ordering\Application\Port\Clock;
use App\Ordering\Application\Port\SalesHours;
use App\Ordering\Application\Port\SalesTotals;
use App\Ordering\Domain\Currency;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sums the hourly sales of the periods of SalesPeriods: at most 2 × 720 hours of 16 rows each, whatever the number of
 * orders.
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetSalesSummaryHandler
{
    public function __construct(
        private SalesHours $sales,
        private Clock $clock,
    ) {
    }

    public function __invoke(GetSalesSummary $query): SalesSummary
    {
        $periods = SalesPeriods::endingAt($this->clock->now());

        return new SalesSummary(
            $periods->until,
            Currency::PLN->value,
            null === $periods->today || null === $periods->yesterday
                ? PeriodSales::none()
                : PeriodSales::compared($this->totals($periods->today), $this->totals($periods->yesterday)),
            PeriodSales::compared($this->totals($periods->last7Days), $this->totals($periods->previous7Days)),
            PeriodSales::compared($this->totals($periods->last30Days), $this->totals($periods->previous30Days)),
        );
    }

    private function totals(SalesPeriod $period): SalesTotals
    {
        return $this->sales->totals($period->from, $period->to);
    }
}
