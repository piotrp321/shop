<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Port\BackofficeOrderFilter;
use App\Ordering\Application\Port\ListedOrder;
use App\Ordering\Application\Query\BackofficeOrderCursor;
use App\Ordering\Application\Query\BackofficeOrderList;
use App\Ordering\Application\Query\InvalidBackofficeOrderCursor;
use App\Ordering\Application\Query\ListBackofficeOrders;
use App\Ordering\Application\Query\ListBackofficeOrdersHandler;
use App\Ordering\Domain\OrderStatus;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use App\Tests\Unit\Ordering\InMemoryBackofficeOrderReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ListBackofficeOrders, the order list of the panel: what the search term means (an exact number, an exact email or
 * nothing), the filters passed to the reader, the keyset paging with one order more than the page, the cursor of the
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
     * @return iterable<string, array{string, ?string, ?string}>
     */
    public static function searches(): iterable
    {
        yield 'a number' => ['R-100001', 'R-100001', null];
        yield 'a number in lower case' => ['r-100001', 'R-100001', null];
        yield 'a number with spaces around' => ['  R-100001 ', 'R-100001', null];
        yield 'an email' => ['jan@example.com', null, 'jan@example.com'];
        yield 'an email in another letter case, as typed' => ['Jan.Kowalski@Example.COM', null, 'Jan.Kowalski@Example.COM'];
        yield 'an email with spaces around' => [' jan@example.com  ', null, 'jan@example.com'];
        yield 'a number-like text with an @' => ['R-1@example.com', null, 'R-1@example.com'];
    }

    #[DataProvider('searches')]
    public function testASearchIsAnExactNumberOrAnExactEmail(string $q, ?string $number, ?string $email): void
    {
        $this->list(new ListBackofficeOrders(null, false, $q, null, 10));

        self::assertEquals([[new BackofficeOrderFilter(null, false, $number, $email), null, 11]], $this->reader->pages);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherSearches(): iterable
    {
        yield 'a name' => ['Kowalski'];
        yield 'digits of a number' => ['100001'];
        yield 'the prefix of a number' => ['R-'];
        yield 'a number with letters' => ['R-10a001'];
        yield 'a number with another prefix' => ['X-100001'];
        yield 'a number with a space inside' => ['R- 100001'];
        yield 'nothing but spaces' => ['   '];
        yield 'an @ with a NUL' => ["jan\0@example.com"];
    }

    #[DataProvider('otherSearches')]
    public function testAnotherSearchIsAnEmptyListWithoutReading(string $q): void
    {
        $this->reader->orders = [self::listed(1)];

        $list = $this->list(new ListBackofficeOrders(null, false, $q, null, 25));

        self::assertEquals(new BackofficeOrderList([], null), $list);
        self::assertSame([], $this->reader->pages);
    }

    public function testAFullPageHasTheCursorOfItsLastOrderForTheNext(): void
    {
        $this->reader->orders = [self::listed(1), self::listed(2), self::listed(3)];

        $list = $this->list(new ListBackofficeOrders(null, false, null, null, 2));

        self::assertSame([self::id(1), self::id(2)], array_map(static fn ($item): string => $item->orderId, $list->items));
        self::assertNotNull($list->nextCursor);
        $cursor = BackofficeOrderCursor::decode($list->nextCursor, false);
        self::assertEquals([new \DateTimeImmutable('2026-11-05 12:00:02+00:00'), self::id(2)], [$cursor->at, $cursor->id], 'placed_at and id of the last order of the page');

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
        $cursor = BackofficeOrderCursor::decode($list->nextCursor, true);
        self::assertEquals([new \DateTimeImmutable('2026-11-19 09:00:00+00:00'), self::id(1)], [$cursor->at, $cursor->id], 'the due date and id of the last order of the page');
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
