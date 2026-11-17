<?php

declare(strict_types=1);

namespace App\Backoffice\UI\Http;

use App\Backoffice\Application\Port\SalesFigures;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'The sales of completed hours, summed from an hourly pre-aggregation by Ordering: revenue is the gross total (after discounts) of the orders paid in the period by the moment of their payment; a later cancellation or refund does not lower it. '
        .'`today` runs from midnight of the Polish clock to `dataUntil` and is compared with yesterday from midnight to the same hour of the clock (on the days the clock changes the two may differ by an hour of real time); between 0:00 and 1:00 every field of `today` is null. '
        .'`last7Days` and `last30Days` are the last 168 and 720 hours, compared with as many hours before them.',
    required: ['dataUntil', 'currency', 'today', 'last7Days', 'last30Days'],
)]
final readonly class DashboardSalesResponse
{
    public function __construct(
        #[OA\Property(description: 'The end of every period: the start of the current hour (UTC). The current hour is not counted.', format: 'date-time', example: '2026-11-15T13:00:00+00:00')]
        public string $dataUntil,
        #[OA\Property(description: 'The currency of the amounts.', example: 'PLN')]
        public string $currency,
        #[OA\Property(ref: new Model(type: DashboardSalesPeriodResponse::class))]
        public DashboardSalesPeriodResponse $today,
        #[OA\Property(ref: new Model(type: DashboardSalesPeriodResponse::class))]
        public DashboardSalesPeriodResponse $last7Days,
        #[OA\Property(ref: new Model(type: DashboardSalesPeriodResponse::class))]
        public DashboardSalesPeriodResponse $last30Days,
    ) {
    }

    public static function ofNullable(?SalesFigures $sales): ?self
    {
        if (null === $sales) {
            return null;
        }

        return new self(
            $sales->dataUntil->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            $sales->currency,
            DashboardSalesPeriodResponse::of($sales->today),
            DashboardSalesPeriodResponse::of($sales->last7Days),
            DashboardSalesPeriodResponse::of($sales->last30Days),
        );
    }
}
