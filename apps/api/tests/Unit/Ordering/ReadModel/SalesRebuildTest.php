<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\ReadModel;

use App\Ordering\Application\Port\SalesTotals;
use App\Ordering\Application\ReadModel\SalesRebuild;
use App\Tests\Unit\Ordering\InMemorySalesHoursRebuild;
use PHPUnit\Framework\TestCase;

/**
 * The sales part of ordering:read-models:rebuild: every hour with sales, from the first, each once and in order, and a
 * summary of what it rebuilt.
 */
final class SalesRebuildTest extends TestCase
{
    public function testRebuildsEveryHourWithSalesInOrderAndReportsTheTotals(): void
    {
        $hours = new InMemorySalesHoursRebuild([
            '2026-11-15 13:00' => new SalesTotals(2, 2_460),
            '2026-11-14 09:00' => new SalesTotals(1, 100),
            '2026-11-15 10:00' => new SalesTotals(0, 0),
        ]);
        $lines = [];

        $summary = new SalesRebuild($hours)->run(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        self::assertSame(['2026-11-14 09:00', '2026-11-15 10:00', '2026-11-15 13:00'], $hours->rebuilt);
        self::assertSame('sales: 3 hours rebuilt, 3 orders, 2560 grosze', $summary);
        self::assertSame([], $lines, 'no progress line below 1000 hours');
    }

    public function testWithoutSalesNothingIsRebuilt(): void
    {
        $hours = new InMemorySalesHoursRebuild([]);

        self::assertSame('sales: 0 hours rebuilt, 0 orders, 0 grosze', new SalesRebuild($hours)->run(static function (): void {}));
        self::assertSame([], $hours->rebuilt);
    }

    public function testReportsTheProgressEveryThousandHours(): void
    {
        $totals = [];
        $start = new \DateTimeImmutable('2026-01-01 00:00:00+00:00');
        for ($hour = 0; $hour < 2_500; ++$hour) {
            $totals[$start->modify("+{$hour} hours")->format('Y-m-d H:i')] = new SalesTotals(1, 10);
        }
        $lines = [];

        new SalesRebuild(new InMemorySalesHoursRebuild($totals))->run(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        self::assertSame(['sales: 1000 hours rebuilt, up to 2026-02-11 15:00 UTC', 'sales: 2000 hours rebuilt, up to 2026-03-25 07:00 UTC'], $lines);
    }

    public function testAFailureStopsTheRebuildAndPassesOn(): void
    {
        $hours = new InMemorySalesHoursRebuild([
            '2026-11-14 09:00' => new SalesTotals(1, 100),
            '2026-11-14 10:00' => new SalesTotals(1, 100),
            '2026-11-14 11:00' => new SalesTotals(1, 100),
        ], failingHour: '2026-11-14 10:00');

        try {
            new SalesRebuild($hours)->run(static function (): void {});
            self::fail('Expected the failure of the hour.');
        } catch (\RuntimeException $failure) {
            self::assertSame('the database went away', $failure->getMessage());
        }
        self::assertSame(['2026-11-14 09:00'], $hours->rebuilt);
    }
}
