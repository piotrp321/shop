<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Port\SalesHours;
use App\Ordering\Application\Port\SalesTotals;
use App\Ordering\Domain\OrderId;

/**
 * The hourly sales in memory: every addition is kept, the totals sum the additions of the hours of a period.
 */
final class InMemorySalesHours implements SalesHours
{
    /** @var list<array{orderId: string, paidAt: \DateTimeImmutable, gross: int}> every addition, in order */
    public array $added = [];

    public function add(OrderId $orderId, \DateTimeImmutable $paidAt, int $gross): void
    {
        $this->added[] = ['orderId' => $orderId->toString(), 'paidAt' => $paidAt, 'gross' => $gross];
    }

    public function totals(\DateTimeImmutable $from, \DateTimeImmutable $to): SalesTotals
    {
        $count = 0;
        $gross = 0;
        foreach ($this->added as $addition) {
            $hour = intdiv($addition['paidAt']->getTimestamp(), 3600) * 3600;
            if ($hour >= $from->getTimestamp() && $hour < $to->getTimestamp()) {
                ++$count;
                $gross += $addition['gross'];
            }
        }

        return new SalesTotals($count, $gross);
    }
}
