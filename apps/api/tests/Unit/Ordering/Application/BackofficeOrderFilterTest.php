<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Port\BackofficeOrderFilter;
use App\Ordering\Application\Query\BackofficeOrderSort;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The combinations of the filter that no index serves cannot be built, whoever builds it.
 */
final class BackofficeOrderFilterTest extends TestCase
{
    /**
     * @return iterable<string, array{callable(): BackofficeOrderFilter}>
     */
    public static function impossible(): iterable
    {
        $day = new \DateTimeImmutable('2026-11-01 00:00:00+00:00');

        yield 'a range with the amount' => [static fn (): BackofficeOrderFilter => new BackofficeOrderFilter(null, false, null, null, BackofficeOrderSort::TotalDescending, null, $day)];
        yield 'an end with the amount' => [static fn (): BackofficeOrderFilter => new BackofficeOrderFilter(null, false, null, null, BackofficeOrderSort::TotalAscending, null, null, $day)];
        yield 'a range with the pending refunds' => [static fn (): BackofficeOrderFilter => new BackofficeOrderFilter(null, true, null, null, null, null, $day)];
        yield 'a fragment with the amount' => [static fn (): BackofficeOrderFilter => new BackofficeOrderFilter(null, false, null, null, BackofficeOrderSort::TotalDescending, 'jan')];
    }

    /**
     * @param callable(): BackofficeOrderFilter $build
     */
    #[DataProvider('impossible')]
    public function testACombinationNoIndexServesCannotBeBuilt(callable $build): void
    {
        $this->expectException(\LogicException::class);

        $build();
    }

    public function testTheRangeAndTheFragmentGoWithTheSortsByPlacementAndWithThePendingRefundsSearch(): void
    {
        $day = new \DateTimeImmutable('2026-11-01 00:00:00+00:00');

        $filter = new BackofficeOrderFilter(null, false, null, null, BackofficeOrderSort::PlacedAscending, 'jan', $day, $day);
        $pending = new BackofficeOrderFilter(null, true, null, null, null, 'jan');

        self::assertSame([BackofficeOrderSort::PlacedAscending, 'jan', $day, $day], [$filter->sort, $filter->fragment, $filter->placedFrom, $filter->placedBefore]);
        self::assertSame(BackofficeOrderSort::RefundDueDate, $pending->sort);
    }
}
