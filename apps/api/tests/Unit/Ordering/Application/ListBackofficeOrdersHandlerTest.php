<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Port\BackofficeOrderFilter;
use App\Ordering\Application\Port\ListedOrder;
use App\Ordering\Application\Query\BackofficeOrderCursor;
use App\Ordering\Application\Query\BackofficeOrderList;
use App\Ordering\Application\Query\BackofficeOrderSort;
use App\Ordering\Application\Query\InvalidBackofficeOrderCursor;
use App\Ordering\Application\Query\InvalidBackofficeOrderList;
use App\Ordering\Application\Query\ListBackofficeOrders;
use App\Ordering\Application\Query\ListBackofficeOrdersHandler;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Domain\BoundedCount;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use App\Tests\Unit\Ordering\InMemoryBackofficeOrderReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ListBackofficeOrders, the order list of the panel: what the search term means (an exact number, a fragment, an exact
 * email too long for a fragment, or a refusal), the range of days, the filters passed to the reader, the keyset paging with one order more than the page, the cursor of the
 * sort in use, and the overdue refunds against the clock.
 */
final class ListBackofficeOrdersHandlerTest extends TestCase
{
    private const string NOW = '2026-11-20 09:00:00+00:00';

    private InMemoryBackofficeOrderReader $reader;
    private FixedOrderingClock $clock;

    protected function setUp(): void
    {
        $this->reader = new InMemoryBackofficeOrderReader();
        $this->clock = new FixedOrderingClock(new \DateTimeImmutable(self::NOW));
    }

    public function testWithoutASearchTheFiltersGoToTheReaderWithOneOrderMoreThanThePage(): void
    {
        $this->reader->orders = [self::listed(1)];

        $list = $this->list(new ListBackofficeOrders(OrderStatus::Paid, false, null, null, 25));

        self::assertEquals([[new BackofficeOrderFilter(OrderStatus::Paid, false, null, null), null, 26]], $this->reader->pages);
        self::assertCount(1, $list->items);
        self::assertNull($list->nextCursor, 'the last page');
        $item = $list->items[0];
        self::assertSame(
            [self::id(1), 'R-100001', 'paid', 'jan@example.com', 'Jan Kowalski', 26_600, 'PLN', 3],
            [$item->orderId, $item->number, $item->status, $item->email, $item->fullName, $item->totalGross, $item->currency, $item->itemCount],
        );
        self::assertEquals(new \DateTimeImmutable('2026-11-05 12:00:01+00:00'), $item->placedAt);
        self::assertNull($item->refund);
    }

    /**
     * @return iterable<string, array{string, ?string, ?string, ?string}> the term, the number, the email, the fragment of the filter
     */
    public static function searches(): iterable
    {
        $email = str_repeat('a', 60).'@'.str_repeat('b', 60).'.example.com';

        yield 'a number' => ['R-100001', 'R-100001', null, null];
        yield 'a number in lower case' => ['r-100001', 'R-100001', null, null];
        yield 'a number with spaces around' => ['  R-100001 ', 'R-100001', null, null];
        yield 'a surname' => ['Kowalski', null, null, 'Kowalski'];
        yield 'digits of a number' => ['100001', null, null, '100001'];
        yield 'a fragment of an email' => ['jan@gm', null, null, 'jan@gm'];
        yield 'a complete email, exact' => ['jan@example.com', null, 'jan@example.com', null];
        yield 'an email in another letter case, as typed' => ['Jan.Kowalski@Example.COM', null, 'Jan.Kowalski@Example.COM', null];
        yield 'an email with spaces around' => [' jan@example.com  ', null, 'jan@example.com', null];
        yield 'a number-like text with an @' => ['R-1@example.com', null, 'R-1@example.com', null];
        yield 'an email without a top-level domain, a fragment' => ['jan@example', null, null, 'jan@example'];
        yield 'an email too long for a fragment' => [$email, null, $email, null];
    }

    #[DataProvider('searches')]
    public function testASearchIsAnExactNumberAnExactEmailOrAFragment(string $q, ?string $number, ?string $email, ?string $fragment): void
    {
        $this->list(new ListBackofficeOrders(null, false, $q, null, 10));

        self::assertEquals([[new BackofficeOrderFilter(null, false, $number, $email, fragment: $fragment), null, 11]], $this->reader->pages);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedSearches(): iterable
    {
        yield 'two characters' => ['ab'];
        yield 'the prefix of a number' => ['R-'];
        yield 'nothing but spaces' => ['   '];
        yield 'over a hundred characters without an @' => [str_repeat('a', 101)];
        yield 'a number with a space inside, over a hundred' => ['R- '.str_repeat('1', 100)];
        yield 'a NUL' => ["jan\0@example.com"];
        yield 'the separator of the index' => ["jan\u{001F}kowalski"];
        yield 'a line break' => ["jan\nkowalski"];
    }

    #[DataProvider('refusedSearches')]
    public function testAnotherSearchIsRefusedWithoutReading(string $q): void
    {
        $this->reader->orders = [self::listed(1)];

        try {
            $this->list(new ListBackofficeOrders(null, false, $q, null, 25));
            self::fail('The search must be refused.');
        } catch (InvalidBackofficeOrderList) {
            self::assertSame([], $this->reader->pages);
            self::assertSame([], $this->reader->counts);
        }
    }

    public function testTheFirstPageWithANextOneCountsTheOrdersOfTheSameFilterWithoutTheCursor(): void
    {
        $this->reader->orders = [self::listed(1), self::listed(2)];
        $this->reader->total = new BoundedCount(10_000, true);

        $list = $this->list(new ListBackofficeOrders(OrderStatus::Paid, false, ' r-100001 ', null, 1));

        self::assertNotNull($list->nextCursor);
        self::assertEquals(new BoundedCount(10_000, true), $list->total);
        self::assertEquals([new BackofficeOrderFilter(OrderStatus::Paid, false, 'R-100001', null)], $this->reader->counts);
    }

    public function testTheFirstPageWithoutANextOneIsTheWholeResultAndIsNotCounted(): void
    {
        $this->reader->orders = [self::listed(1), self::listed(2)];
        $this->reader->total = new BoundedCount(99, true);

        $list = $this->list(new ListBackofficeOrders(null, false, null, null, 2));

        self::assertNull($list->nextCursor);
        self::assertEquals(new BoundedCount(2, false), $list->total, 'the rows of the page are the exact count');
        self::assertSame([], $this->reader->counts);
    }

    public function testTheFirstPageWithNoOrderIsAnExactZeroWithoutACount(): void
    {
        $list = $this->list(new ListBackofficeOrders(null, false, null, null, 25));

        self::assertEquals(new BoundedCount(0, false), $list->total);
        self::assertSame([], $this->reader->counts);
    }

    public function testALaterPageDoesNotCountAndHasNoTotal(): void
    {
        $this->reader->orders = [self::listed(1), self::listed(2), self::listed(3)];
        $first = $this->list(new ListBackofficeOrders(null, false, null, null, 2));
        self::assertNotNull($first->nextCursor);

        $later = $this->list(new ListBackofficeOrders(null, false, null, $first->nextCursor, 2));

        self::assertNull($later->total);
        self::assertCount(1, $this->reader->counts, 'only the first page counted');
    }

    public function testAFullPageHasTheCursorOfItsLastOrderForTheNext(): void
    {
        $this->reader->orders = [self::listed(1), self::listed(2), self::listed(3)];

        $list = $this->list(new ListBackofficeOrders(null, false, null, null, 2));

        self::assertSame([self::id(1), self::id(2)], array_map(static fn ($item): string => $item->orderId, $list->items));
        self::assertNotNull($list->nextCursor);
        $cursor = BackofficeOrderCursor::decode($list->nextCursor, BackofficeOrderSort::PlacedDescending);
        self::assertEquals([new \DateTimeImmutable('2026-11-05 12:00:02+00:00'), self::id(2)], [$cursor->value, $cursor->id], 'placed_at and id of the last order of the page');

        $this->list(new ListBackofficeOrders(null, false, null, $list->nextCursor, 2));

        self::assertEquals($cursor, $this->reader->pages[1][1]);
    }

    public function testThePendingRefundsArePagedByTheirDueDate(): void
    {
        $this->reader->orders = [
            self::listed(1, refund: [26_600, '2026-11-19 09:00:00+00:00', null]),
            self::listed(2, refund: [26_600, '2026-11-21 09:00:00+00:00', null]),
        ];

        $list = $this->list(new ListBackofficeOrders(OrderStatus::Cancelled, true, null, null, 1));

        self::assertEquals(new BackofficeOrderFilter(OrderStatus::Cancelled, true, null, null), $this->reader->pages[0][0]);
        self::assertNotNull($list->nextCursor);
        $cursor = BackofficeOrderCursor::decode($list->nextCursor, BackofficeOrderSort::RefundDueDate);
        self::assertEquals([new \DateTimeImmutable('2026-11-19 09:00:00+00:00'), self::id(1)], [$cursor->value, $cursor->id], 'the due date and id of the last order of the page');
    }

    public function testTheOverdueRefundsAreThePendingOnesDueBeforeTheMomentOfTheRead(): void
    {
        $this->reader->orders = [self::listed(1, refund: [26_600, '2026-11-19 09:00:00+00:00', null])];

        $list = $this->list(new ListBackofficeOrders(OrderStatus::Returned, true, 'R-100001', null, 25, null, null, null, true));
        $this->list(new ListBackofficeOrders(null, true, null, null, 25));

        $overdue = $this->reader->pages[0][0];
        self::assertEquals(new \DateTimeImmutable(self::NOW), $overdue->refundDueBefore);
        self::assertSame([BackofficeOrderSort::RefundDueDate, true, OrderStatus::Returned, 'R-100001'], [$overdue->sort, $overdue->pendingRefunds, $overdue->status, $overdue->number]);
        self::assertNull($this->reader->pages[1][0]->refundDueBefore, 'the pending refunds are not limited by the due date');
        self::assertTrue($list->items[0]->refund?->overdue);
    }

    public function testACursorOfThePendingRefundsLeadsTheOverdueOnesOnAndTheOtherWayRound(): void
    {
        $this->reader->orders = [self::listed(1, refund: [26_600, '2026-11-19 09:00:00+00:00', null]), self::listed(2, refund: [26_600, '2026-11-19 10:00:00+00:00', null])];
        $pending = $this->list(new ListBackofficeOrders(null, true, null, null, 1))->nextCursor;
        $overdue = $this->list(new ListBackofficeOrders(null, true, null, null, 1, null, null, null, true))->nextCursor;
        self::assertNotNull($pending);
        self::assertNotNull($overdue);

        $this->list(new ListBackofficeOrders(null, true, null, $pending, 1, null, null, null, true));
        $this->list(new ListBackofficeOrders(null, true, null, $overdue, 1));

        self::assertSame($pending, $overdue, 'the same cursor');
        self::assertCount(4, $this->reader->pages);
    }

    public function testACursorOfThePlacementSortIsRefusedByTheOverdueRefunds(): void
    {
        $this->reader->orders = [self::listed(1), self::listed(2)];
        $byDate = $this->list(new ListBackofficeOrders(null, false, null, null, 1))->nextCursor;
        self::assertNotNull($byDate);
        $this->expectException(InvalidBackofficeOrderCursor::class);

        $this->list(new ListBackofficeOrders(null, true, null, $byDate, 1, null, null, null, true));
    }

    public function testACursorOfTheOtherSortIsRefused(): void
    {
        $this->reader->orders = [self::listed(1, refund: [26_600, '2026-11-19 09:00:00+00:00', null]), self::listed(2)];
        $byDate = $this->list(new ListBackofficeOrders(null, false, null, null, 1))->nextCursor;
        $byRefund = $this->list(new ListBackofficeOrders(null, true, null, null, 1))->nextCursor;
        self::assertNotNull($byDate);
        self::assertNotNull($byRefund);

        foreach ([[$byDate, true], [$byRefund, false]] as [$cursor, $pendingRefunds]) {
            try {
                $this->list(new ListBackofficeOrders(null, $pendingRefunds, null, $cursor, 1));
                self::fail('A cursor of the other sort is no cursor.');
            } catch (InvalidBackofficeOrderCursor) {
            }
        }
        self::assertCount(2, $this->reader->pages);
    }

    public function testABadCursorIsRefusedBeforeReadingAndBeforeTheSearch(): void
    {
        $this->expectException(InvalidBackofficeOrderCursor::class);

        try {
            $this->list(new ListBackofficeOrders(null, false, 'Kowalski', 'not-a-cursor', 25));
        } finally {
            self::assertSame([], $this->reader->pages);
        }
    }

    public function testWithoutASortTheNewestComeFirst(): void
    {
        $this->list(new ListBackofficeOrders(null, false, null, null, 25));

        self::assertSame(BackofficeOrderSort::PlacedDescending, $this->reader->pages[0][0]->sort);
    }

    public function testThePendingRefundsAreSortedByTheirDueDate(): void
    {
        $this->list(new ListBackofficeOrders(null, true, null, null, 25));

        self::assertSame(BackofficeOrderSort::RefundDueDate, $this->reader->pages[0][0]->sort);
    }

    /**
     * @return iterable<string, array{BackofficeOrderSort}>
     */
    public static function sorts(): iterable
    {
        yield 'newest first' => [BackofficeOrderSort::PlacedDescending];
        yield 'oldest first' => [BackofficeOrderSort::PlacedAscending];
        yield 'highest amount first' => [BackofficeOrderSort::TotalDescending];
        yield 'lowest amount first' => [BackofficeOrderSort::TotalAscending];
    }

    #[DataProvider('sorts')]
    public function testASortGoesToTheReaderAndTheNextCursorKeepsItWithThePositionOfTheLastOrder(BackofficeOrderSort $sort): void
    {
        $this->reader->orders = [self::listed(1), self::listed(2), self::listed(3)];

        $list = $this->list(new ListBackofficeOrders(OrderStatus::Paid, false, null, null, 2, $sort));

        self::assertSame($sort, $this->reader->pages[0][0]->sort);
        self::assertNotNull($list->nextCursor);
        $cursor = BackofficeOrderCursor::decode($list->nextCursor, $sort);
        self::assertEquals($sort->byTotal() ? 26_600 : new \DateTimeImmutable('2026-11-05 12:00:02+00:00'), $cursor->value);
        self::assertSame(self::id(2), $cursor->id);

        $this->list(new ListBackofficeOrders(OrderStatus::Paid, false, null, $list->nextCursor, 2, $sort));

        self::assertEquals($cursor, $this->reader->pages[1][1]);
    }

    public function testACursorOfAnotherSortOfTheSameFamilyIsRefusedWithoutARead(): void
    {
        $this->reader->orders = [self::listed(1), self::listed(2)];
        $cursor = $this->list(new ListBackofficeOrders(null, false, null, null, 1, BackofficeOrderSort::TotalDescending))->nextCursor;
        self::assertNotNull($cursor);
        $reads = $this->reader->pages;

        foreach ([null, BackofficeOrderSort::PlacedAscending, BackofficeOrderSort::TotalAscending] as $sort) {
            try {
                $this->list(new ListBackofficeOrders(null, false, null, $cursor, 1, $sort));
                self::fail('A cursor of another sort is no cursor.');
            } catch (InvalidBackofficeOrderCursor) {
            }
        }
        self::assertSame($reads, $this->reader->pages);
    }

    /**
     * @return iterable<string, array{ListBackofficeOrders}>
     */
    public static function refusedCombinations(): iterable
    {
        yield 'a sort with the pending refunds' => [new ListBackofficeOrders(null, true, null, null, 25, BackofficeOrderSort::PlacedAscending)];
        yield 'the amount with a search' => [new ListBackofficeOrders(null, false, 'R-100001', null, 25, BackofficeOrderSort::TotalDescending)];
        yield 'the ascending amount with a search' => [new ListBackofficeOrders(OrderStatus::Paid, false, 'jan@example.com', null, 25, BackofficeOrderSort::TotalAscending)];
        yield 'the due date of the refunds as a sort' => [new ListBackofficeOrders(null, false, null, null, 25, BackofficeOrderSort::RefundDueDate)];
        yield 'the amount with the start of a range' => [new ListBackofficeOrders(null, false, null, null, 25, BackofficeOrderSort::TotalDescending, '2026-11-01')];
        yield 'the ascending amount with the end of a range' => [new ListBackofficeOrders(null, false, null, null, 25, BackofficeOrderSort::TotalAscending, null, '2026-11-30')];
        yield 'the pending refunds with the start of a range' => [new ListBackofficeOrders(null, true, null, null, 25, null, '2026-11-01')];
        yield 'the pending refunds with the end of a range' => [new ListBackofficeOrders(OrderStatus::Returned, true, null, null, 25, null, null, '2026-11-30')];
        yield 'a sort with the overdue refunds' => [new ListBackofficeOrders(null, true, null, null, 25, BackofficeOrderSort::PlacedAscending, null, null, true)];
        yield 'the overdue refunds with the start of a range' => [new ListBackofficeOrders(null, true, null, null, 25, null, '2026-11-01', null, true)];
        yield 'the overdue refunds with the end of a range' => [new ListBackofficeOrders(OrderStatus::Returned, true, null, null, 25, null, null, '2026-11-30', true)];
        yield 'an inverted range' => [new ListBackofficeOrders(null, false, null, null, 25, null, '2026-11-30', '2026-11-01')];
        yield 'a day that is no date' => [new ListBackofficeOrders(null, false, null, null, 25, null, '2026-02-30')];
        yield 'an end that is no date' => [new ListBackofficeOrders(null, false, null, null, 25, null, null, 'tomorrow')];
    }

    #[DataProvider('refusedCombinations')]
    public function testACombinationNoIndexServesIsRefusedWithoutARead(ListBackofficeOrders $query): void
    {
        try {
            $this->list($query);
            self::fail('The combination must be refused.');
        } catch (InvalidBackofficeOrderList) {
            self::assertSame([], $this->reader->pages);
            self::assertSame([], $this->reader->counts);
        }
    }

    public function testThePlacementSortsAcceptASearch(): void
    {
        $this->list(new ListBackofficeOrders(null, false, 'R-100001', null, 25, BackofficeOrderSort::PlacedAscending));

        self::assertSame(BackofficeOrderSort::PlacedAscending, $this->reader->pages[0][0]->sort);
    }

    public function testTheRangeOfDaysGoesToTheReaderAsInstantsOfUtcAndCombinesWithTheStatusTheSearchAndTheDateSort(): void
    {
        $this->list(new ListBackofficeOrders(OrderStatus::Paid, false, 'Kowalski', null, 25, BackofficeOrderSort::PlacedAscending, '2026-03-29', '2026-03-29'));

        $filter = $this->reader->pages[0][0];
        self::assertSame(BackofficeOrderSort::PlacedAscending, $filter->sort);
        self::assertSame('Kowalski', $filter->fragment);
        self::assertSame(OrderStatus::Paid, $filter->status);
        self::assertSame('2026-03-28T23:00:00+00:00', $filter->placedFrom?->format(\DATE_ATOM));
        self::assertSame('2026-03-29T22:00:00+00:00', $filter->placedBefore?->format(\DATE_ATOM), 'the day of the change has 23 hours');
    }

    public function testOneEndOfTheRangeIsEnough(): void
    {
        $this->list(new ListBackofficeOrders(null, false, null, null, 25, null, null, '2026-11-30'));
        $this->list(new ListBackofficeOrders(null, false, null, null, 25, null, '2026-11-01'));

        [[$untilFilter], [$sinceFilter]] = $this->reader->pages;
        self::assertSame([null, '2026-11-30T23:00:00+00:00'], [$untilFilter->placedFrom, $untilFilter->placedBefore?->format(\DATE_ATOM)]);
        self::assertSame(['2026-10-31T23:00:00+00:00', null], [$sinceFilter->placedFrom?->format(\DATE_ATOM), $sinceFilter->placedBefore]);
    }

    public function testTheCountOfTheFirstPageHasTheRangeToo(): void
    {
        $this->reader->orders = [self::listed(1), self::listed(2)];

        $this->list(new ListBackofficeOrders(null, false, null, null, 1, null, '2026-11-01', '2026-11-30'));

        self::assertEquals($this->reader->pages[0][0], $this->reader->counts[0]);
        self::assertNotNull($this->reader->counts[0]->placedFrom);
    }

    /**
     * @return iterable<string, array{array{int, string, ?string}, bool}>
     */
    public static function refunds(): iterable
    {
        yield 'pending before its due date' => [[26_600, '2026-11-21 09:00:00+00:00', null], false];
        yield 'pending at its due date' => [[26_600, self::NOW, null], false];
        yield 'pending past its due date' => [[26_600, '2026-11-20 08:59:59+00:00', null], true];
        yield 'completed past its due date' => [[26_600, '2026-11-01 09:00:00+00:00', '2026-11-02 10:00:00+00:00'], false];
    }

    /**
     * @param array{int, string, ?string} $refund
     */
    #[DataProvider('refunds')]
    public function testARefundIsOverdueWhenPendingPastItsDueDate(array $refund, bool $overdue): void
    {
        $this->reader->orders = [self::listed(1, refund: $refund)];

        $listed = $this->list(new ListBackofficeOrders(null, false, null, null, 25))->items[0]->refund;

        self::assertNotNull($listed);
        self::assertSame([$refund[0], $overdue], [$listed->amount, $listed->overdue]);
        self::assertEquals(new \DateTimeImmutable($refund[1]), $listed->dueAt);
        self::assertEquals(null === $refund[2] ? null : new \DateTimeImmutable($refund[2]), $listed->completedAt);
    }

    private function list(ListBackofficeOrders $query): BackofficeOrderList
    {
        return new ListBackofficeOrdersHandler($this->reader, $this->clock)($query);
    }

    private static function id(int $n): string
    {
        return \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $n);
    }

    /**
     * An order placed at 12:00:0n.
     *
     * @param array{int, string, ?string}|null $refund amount, due date and completion
     */
    private static function listed(int $n, ?array $refund = null): ListedOrder
    {
        return new ListedOrder(
            self::id($n),
            'R-'.(100_000 + $n),
            new \DateTimeImmutable(\sprintf('2026-11-05 12:00:%02d+00:00', $n)),
            null === $refund ? 'paid' : 'cancelled',
            'jan@example.com',
            'Jan Kowalski',
            26_600,
            'PLN',
            3,
            $refund[0] ?? null,
            null === $refund ? null : new \DateTimeImmutable($refund[1]),
            null === $refund || null === $refund[2] ? null : new \DateTimeImmutable($refund[2]),
        );
    }
}
