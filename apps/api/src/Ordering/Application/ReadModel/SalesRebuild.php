<?php

declare(strict_types=1);

namespace App\Ordering\Application\ReadModel;

use App\Ordering\Application\Port\SalesHoursRebuild;

/**
 * The sales part of ordering:read-models:rebuild: rebuilds every hour with paid orders or rows of sales, from the
 * first one on, each in a transaction of its own (SalesHoursRebuild), so it is correct while payments come in, may
 * be stopped at any hour and run again from the start. Hours without either are skipped: a payment in such an hour
 * after the rebuild looked adds itself to an empty hour, which is right.
 */
final readonly class SalesRebuild
{
    private const int PROGRESS_EVERY = 1000;

    public function __construct(private SalesHoursRebuild $hours)
    {
    }

    /**
     * @param \Closure(string): void $report takes a progress line every 1000 hours
     *
     * @return string the summary
     *
     * @throws \Throwable when an hour cannot be rebuilt; the hours before it stay rebuilt
     */
    public function run(\Closure $report): string
    {
        $rebuilt = 0;
        $orders = 0;
        $gross = 0;
        $hour = $this->hours->nextHour(null);
        while (null !== $hour) {
            $totals = $this->hours->rebuild($hour);
            ++$rebuilt;
            $orders += $totals->orderCount;
            $gross += $totals->gross;
            if (0 === $rebuilt % self::PROGRESS_EVERY) {
                $report(\sprintf('sales: %d hours rebuilt, up to %s UTC', $rebuilt, $hour->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i')));
            }
            $hour = $this->hours->nextHour($hour->modify('+1 hour'));
        }

        return \sprintf('sales: %d hours rebuilt, %d orders, %d grosze', $rebuilt, $orders, $gross);
    }
}
