<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Persistence;

use App\Ordering\Application\Port\BackofficeOrderFilter;
use App\Ordering\Application\Port\ListedOrder;
use App\Ordering\Application\Query\BackofficeOrderCursor;
use App\Ordering\Application\Query\BackofficeOrderSort;
use App\Ordering\Domain\OrderStatus;
use App\Ordering\Infrastructure\Persistence\Doctrine\DbalBackofficeOrderReader;
use App\Shared\Infrastructure\Persistence\BoundedCounter;
use App\Tests\Integration\Ordering\BackofficeOrderFixture;
use App\Tests\Support\RecordingConnection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalBackofficeOrderReader on shop_test (rolled back after each test, with no other order in the transaction): the
 * filters, the sorts with their keyset cursors, and what a listed order carries.
 */
final class DbalBackofficeOrderReaderTest extends KernelTestCase
{
    private Connection $connection;
    private DbalBackofficeOrderReader $reader;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
        $this->reader = new DbalBackofficeOrderReader($this->connection, new BoundedCounter($this->connection));
        BackofficeOrderFixture::noOrders($this->connection);
    }

    public function testNewestFirstThenByIdDescending(): void
    {
        $older = $this->order('paid', '2026-11-05 10:00:00', 'R-100001');
        $tieLow = $this->order('new', '2026-11-05 11:00:00', 'R-100002', orderId: '0192f0c4-7c1a-7c4e-9a6e-000000000001');
        $tieHigh = $this->order('shipped', '2026-11-05 11:00:00', 'R-100003', orderId: '0192f0c4-7c1a-7c4e-9a6e-000000000002');
        $newest = $this->order('cancelled', '2026-11-05 12:00:00', 'R-100004');

        self::assertSame([$newest, $tieHigh, $tieLow, $older], $this->ids($this->reader->page(self::all(), null, 10)));
        self::assertSame([$newest, $tieHigh], $this->ids($this->reader->page(self::all(), null, 2)), 'at most the limit');
    }

    public function testAListedOrderCarriesItsDataUnitsAndRefund(): void
    {
        $orderId = $this->order('cancelled_by_staff', '2026-11-05 10:00:00', 'R-100042', email: 'Anna.Nowak@Example.com', quantities: [2, 3], refundDueAt: '2026-11-19 11:00:00+00:00');
        $completed = $this->order('returned', '2026-11-04 10:00:00', 'R-100043', refundDueAt: '2026-11-18 11:00:00+00:00', refundCompletedAt: '2026-11-10 08:30:00+00:00');
        $this->order('paid', '2026-11-03 10:00:00', 'R-100044');

        [$cancelled, $returned, $paid] = $this->reader->page(self::all(), null, 10);

        self::assertEquals(
            new ListedOrder($orderId, 'R-100042', new \DateTimeImmutable('2026-11-05 10:00:00+00:00'), 'cancelled', 'Anna.Nowak@Example.com', 'Jan Kowalski', 5_000, 'PLN', 5, 5_000, new \DateTimeImmutable('2026-11-19 11:00:00+00:00'), null),
            $cancelled,
        );
        self::assertSame($completed, $returned->orderId);
        self::assertEquals(new \DateTimeImmutable('2026-11-10 08:30:00+00:00'), $returned->refundCompletedAt);
        self::assertSame([null, null, null, 1], [$paid->refundAmount, $paid->refundDueAt, $paid->refundCompletedAt, $paid->itemCount]);
    }

    public function testTheStatusFilter(): void
    {
        $this->order('paid', '2026-11-05 10:00:00', 'R-100001');
        $shipped = $this->order('shipped', '2026-11-05 11:00:00', 'R-100002');
        $this->order('delivered', '2026-11-05 12:00:00', 'R-100003');
        $alsoShipped = $this->order('shipped', '2026-11-05 13:00:00', 'R-100004');

        self::assertSame([$alsoShipped, $shipped], $this->ids($this->reader->page(new BackofficeOrderFilter(OrderStatus::Shipped, false, null, null), null, 10)));
        self::assertSame([], $this->reader->page(new BackofficeOrderFilter(OrderStatus::Returned, false, null, null), null, 10));
    }

    public function testTheNumberIsExact(): void
    {
        $this->order('paid', '2026-11-05 10:00:00', 'R-1000010');
        $wanted = $this->order('paid', '2026-11-05 11:00:00', 'R-100001');

        self::assertSame([$wanted], $this->ids($this->reader->page(new BackofficeOrderFilter(null, false, 'R-100001', null), null, 10)));
        self::assertSame([], $this->reader->page(new BackofficeOrderFilter(null, false, 'R-10000', null), null, 10), 'no prefix match');
        self::assertSame([], $this->reader->page(new BackofficeOrderFilter(OrderStatus::Shipped, false, 'R-100001', null), null, 10), 'combined with the status');
    }

    public function testTheEmailIsExactRegardlessOfLetterCase(): void
    {
        $first = $this->order('paid', '2026-11-05 10:00:00', 'R-100001', email: 'Jan.Kowalski@Example.com');
        $second = $this->order('new', '2026-11-05 11:00:00', 'R-100002', email: 'jan.kowalski@example.com');
        $this->order('paid', '2026-11-05 12:00:00', 'R-100003', email: 'jan.kowalski@example.com.pl');
        $this->order('paid', '2026-11-05 13:00:00', 'R-100004', email: 'anna@example.com');

        self::assertSame([$second, $first], $this->ids($this->reader->page(new BackofficeOrderFilter(null, false, null, 'JAN.kowalski@EXAMPLE.com'), null, 10)));
        self::assertSame([], $this->reader->page(new BackofficeOrderFilter(null, false, null, 'kowalski@example.com'), null, 10), 'no part of an email');
        self::assertSame([], $this->reader->page(new BackofficeOrderFilter(null, false, null, 'jan.kowalski@example.%'), null, 10), 'no pattern');
        self::assertSame([$first], $this->ids($this->reader->page(new BackofficeOrderFilter(OrderStatus::Paid, false, null, 'jan.kowalski@example.com'), null, 10)), 'combined with the status');
    }

    public function testThePendingRefundsNearestDueDateFirstWithoutTheCompletedOnes(): void
    {
        $this->order('paid', '2026-11-01 10:00:00', 'R-100001');
        $later = $this->order('returned', '2026-11-01 10:00:00', 'R-100002', refundDueAt: '2026-11-20 10:00:00+00:00');
        $tieHigh = $this->order('cancelled_by_staff', '2026-11-02 10:00:00', 'R-100003', refundDueAt: '2026-11-15 10:00:00+00:00', orderId: '0192f0c4-7c1a-7c4e-9a6e-000000000002');
        $tieLow = $this->order('returned', '2026-11-03 10:00:00', 'R-100004', refundDueAt: '2026-11-15 10:00:00+00:00', orderId: '0192f0c4-7c1a-7c4e-9a6e-000000000001');
        $this->order('returned', '2026-11-04 10:00:00', 'R-100005', refundDueAt: '2026-11-10 10:00:00+00:00', refundCompletedAt: '2026-11-09 10:00:00+00:00');
        $this->order('cancelled', '2026-11-05 10:00:00', 'R-100006');

        self::assertSame([$tieLow, $tieHigh, $later], $this->ids($this->reader->page(self::pendingRefunds(), null, 10)));
        self::assertSame([$tieLow, $later], $this->ids($this->reader->page(new BackofficeOrderFilter(OrderStatus::Returned, true, null, null), null, 10)), 'combined with the status');
        self::assertSame([$tieHigh], $this->ids($this->reader->page(new BackofficeOrderFilter(OrderStatus::Cancelled, true, 'R-100003', null), null, 10)), 'and with the number');
    }

    public function testTheOverdueRefundsAreTheStrictlyPastDueOnesOfThePendingAndKeepTheirOrderAndCursor(): void
    {
        $now = new \DateTimeImmutable('2026-11-20 09:00:00+00:00');
        $this->order('paid', '2026-11-01 10:00:00', 'R-100001');
        $pastLater = $this->order('returned', '2026-11-01 10:00:00', 'R-100002', refundDueAt: '2026-11-19 10:00:00+00:00');
        $pastTieHigh = $this->order('cancelled_by_staff', '2026-11-02 10:00:00', 'R-100003', refundDueAt: '2026-11-15 10:00:00+00:00', orderId: '0192f0c4-7c1a-7c4e-9a6e-000000000002');
        $pastTieLow = $this->order('returned', '2026-11-03 10:00:00', 'R-100004', refundDueAt: '2026-11-15 10:00:00+00:00', orderId: '0192f0c4-7c1a-7c4e-9a6e-000000000001');
        $this->order('returned', '2026-11-04 10:00:00', 'R-100005', refundDueAt: '2026-11-20 09:00:00+00:00');
        $this->order('returned', '2026-11-04 10:00:00', 'R-100006', refundDueAt: '2026-11-25 10:00:00+00:00');
        $this->order('returned', '2026-11-04 10:00:00', 'R-100007', refundDueAt: '2026-11-10 10:00:00+00:00', refundCompletedAt: '2026-11-11 10:00:00+00:00');
        $overdue = new BackofficeOrderFilter(null, true, null, null, null, null, null, null, $now);

        $all = $this->reader->page($overdue, null, 10);

        self::assertSame([$pastTieLow, $pastTieHigh, $pastLater], $this->ids($all), 'due before the moment, not at it, not completed, the due date first then the id');
        self::assertCount(5, $this->reader->page(self::pendingRefunds(), null, 10), 'the pending refunds are a superset');
        self::assertSame([$pastTieLow, $pastLater], $this->ids($this->reader->page(new BackofficeOrderFilter(OrderStatus::Returned, true, null, null, null, null, null, null, $now), null, 10)), 'with the status');
        self::assertSame([$pastTieHigh], $this->ids($this->reader->page(new BackofficeOrderFilter(null, true, 'R-100003', null, null, null, null, null, $now), null, 10)), 'with the number');
        self::assertSame([$pastTieLow], $this->ids($this->reader->page(new BackofficeOrderFilter(null, true, null, null, null, 'R-100004', null, null, $now), null, 10)), 'with a fragment');
        self::assertSame([3, false], [$this->reader->count($overdue)->value, $this->reader->count($overdue)->capped]);
        $seen = $this->pageThrough($overdue, 1);
        self::assertSame($this->ids($all), $seen, 'one at a time, each once');
        $pendingCursor = BackofficeOrderCursor::after($all[0], BackofficeOrderSort::RefundDueDate);
        self::assertSame([$pastTieHigh, $pastLater], $this->ids($this->reader->page($overdue, $pendingCursor, 10)), 'the cursor of a pending page leads on');
    }

    public function testTheOverdueRefundsAreReadThroughThePartialIndexOfThePendingOnesWithoutASort(): void
    {
        $this->seed();
        $recording = RecordingConnection::like($this->connection);
        $reader = new DbalBackofficeOrderReader($recording, new BoundedCounter($recording));
        $filter = new BackofficeOrderFilter(null, true, null, null, null, null, null, null, new \DateTimeImmutable('2026-11-20 09:00:00+00:00'));

        $reader->page($filter, null, 26);
        $page = $recording->planOfTheLastQuery($this->connection);
        $reader->count($filter);
        $count = $recording->planOfTheLastQuery($this->connection);

        foreach ([$page, $count] as $plan) {
            self::assertStringContainsString('ordering_order_pending_refund_idx', $plan);
            self::assertStringNotContainsString('Seq Scan', $plan);
        }
        self::assertStringNotContainsString('Sort', $page);
    }

    /**
     * @return iterable<string, array{BackofficeOrderSort}>
     */
    public static function sorts(): iterable
    {
        foreach ([BackofficeOrderSort::PlacedDescending, BackofficeOrderSort::PlacedAscending, BackofficeOrderSort::TotalDescending, BackofficeOrderSort::TotalAscending] as $sort) {
            yield $sort->value => [$sort];
        }
    }

    /**
     * Seven orders; the totals (1 000 a unit) and the moments of placement both have ties, so the id has to decide.
     *
     * @return list<array{id: string, placedAt: string, total: int, status: string}>
     */
    private function seed(): array
    {
        $rows = [];
        $specs = [
            [3, '2026-11-05 10:00:00', 'paid'], [1, '2026-11-05 11:00:00', 'new'], [3, '2026-11-05 11:00:00', 'paid'], [2, '2026-11-05 12:00:00', 'shipped'],
            [3, '2026-11-05 10:00:00', 'new'], [1, '2026-11-05 13:00:00', 'paid'], [5, '2026-11-05 11:00:00', 'paid'],
        ];
        foreach ($specs as $n => [$units, $placedAt, $status]) {
            /** @var 'new'|'paid'|'shipped' $status */
            $rows[] = ['id' => $this->order($status, $placedAt, 'R-'.(100_001 + $n), quantities: [$units]), 'placedAt' => $placedAt, 'total' => $units * 1_000, 'status' => $status];
        }

        return $rows;
    }

    /**
     * @param list<array{id: string, placedAt: string, total: int, status: string}> $rows
     *
     * @return list<string> the ids in the order of the sort, the id breaking the ties in the direction of the sort
     */
    private static function expected(array $rows, BackofficeOrderSort $sort): array
    {
        $column = $sort->byTotal() ? 'total' : 'placedAt';
        usort($rows, static fn (array $a, array $b): int => [$a[$column], $a['id']] <=> [$b[$column], $b['id']]);
        if ($sort->isDescending()) {
            $rows = array_reverse($rows);
        }

        return array_column($rows, 'id');
    }

    #[DataProvider('sorts')]
    public function testEverySortOrdersByItsColumnWithTheIdBreakingTheTies(BackofficeOrderSort $sort): void
    {
        $rows = $this->seed();
        $filter = new BackofficeOrderFilter(null, false, null, null, $sort);

        self::assertSame(self::expected($rows, $sort), $this->ids($this->reader->page($filter, null, 10)));
        self::assertSame(self::expected($rows, $sort), $this->pageThrough($filter, 3), 'the keyset visits every order once across the ties');
    }

    #[DataProvider('sorts')]
    public function testTheStatusFilterKeepsTheSortAndItsKeyset(BackofficeOrderSort $sort): void
    {
        $rows = $this->seed();
        $paid = array_values(array_filter($rows, static fn (array $row): bool => 'paid' === $row['status']));
        $filter = new BackofficeOrderFilter(OrderStatus::Paid, false, null, null, $sort);

        self::assertSame(self::expected($paid, $sort), $this->pageThrough($filter, 2));
    }

    public function testACursorOfAnotherSortIsRefusedByTheReader(): void
    {
        $this->seed();
        $page = $this->reader->page(new BackofficeOrderFilter(null, false, null, null, BackofficeOrderSort::TotalDescending), null, 2);
        $cursor = BackofficeOrderCursor::decode(BackofficeOrderCursor::after($page[1], BackofficeOrderSort::TotalDescending)->encode(), BackofficeOrderSort::TotalDescending);

        $this->expectException(\InvalidArgumentException::class);

        $this->reader->page(new BackofficeOrderFilter(null, false, null, null, BackofficeOrderSort::TotalAscending), $cursor, 2);
    }

    /**
     * @return iterable<string, array{BackofficeOrderSort, ?OrderStatus, string}>
     */
    public static function indexedFirstPages(): iterable
    {
        yield 'newest first' => [BackofficeOrderSort::PlacedDescending, null, 'ordering_order_placed_at_id_idx'];
        yield 'oldest first' => [BackofficeOrderSort::PlacedAscending, null, 'ordering_order_placed_at_id_idx'];
        yield 'highest amount first' => [BackofficeOrderSort::TotalDescending, null, 'ordering_order_total_gross_id_idx'];
        yield 'lowest amount first' => [BackofficeOrderSort::TotalAscending, null, 'ordering_order_total_gross_id_idx'];
        yield 'newest first of a status' => [BackofficeOrderSort::PlacedDescending, OrderStatus::Paid, 'ordering_order_status_placed_at_id_idx'];
        yield 'oldest first of a status' => [BackofficeOrderSort::PlacedAscending, OrderStatus::Paid, 'ordering_order_status_placed_at_id_idx'];
        yield 'highest amount first of a status' => [BackofficeOrderSort::TotalDescending, OrderStatus::Paid, 'ordering_order_status_total_gross_id_idx'];
        yield 'lowest amount first of a status' => [BackofficeOrderSort::TotalAscending, OrderStatus::Paid, 'ordering_order_status_total_gross_id_idx'];
        yield 'the pending refunds' => [BackofficeOrderSort::RefundDueDate, null, 'ordering_order_pending_refund_idx'];
    }

    #[DataProvider('indexedFirstPages')]
    public function testTheFirstPageOfEverySortIsServedByItsIndexWithoutASort(BackofficeOrderSort $sort, ?OrderStatus $status, string $index): void
    {
        $this->seed();
        $recording = RecordingConnection::like($this->connection);
        $reader = new DbalBackofficeOrderReader($recording, new BoundedCounter($recording));

        $reader->page(new BackofficeOrderFilter($status, BackofficeOrderSort::RefundDueDate === $sort, null, null, $sort), null, 26);
        $plan = $recording->planOfTheLastQuery($this->connection);

        self::assertStringContainsString($index, $plan);
        self::assertStringNotContainsString('Sort', $plan);
        self::assertStringNotContainsString('Seq Scan', $plan);
    }

    /**
     * @return iterable<string, array{string, string}> the fragment, the number of the one order it finds
     */
    public static function fragments(): iterable
    {
        yield 'the end of a number' => ['00456', 'R-100456'];
        yield 'a number with its prefix, in lower case' => ['r-100456', 'R-100456'];
        yield 'a part of an email' => ['nowak@exam', 'R-100123'];
        yield 'an email in capitals' => ['ANNA.NOWAK@EXAMPLE.COM', 'R-100123'];
        yield 'a fragment with an @' => ['jan@gm', 'R-100456'];
        yield 'a surname' => ['wiśniew', 'R-100789'];
        yield 'a surname in capitals, with Polish letters' => ['ŻÓŁĆ', 'R-100456'];
        yield 'a name and a surname' => ['Łukasz Żółć', 'R-100456'];
        yield 'a percent sign, literally' => ['50%', 'R-100901'];
        yield 'an underscore, literally' => ['a_b', 'R-100902'];
        yield 'a backslash, literally' => ['c\\d', 'R-100903'];
        yield 'a quote, literally' => ["o'br", 'R-100904'];
    }

    #[DataProvider('fragments')]
    public function testAFragmentFindsTheOrderByNumberEmailOrNameRegardlessOfLetterCase(string $fragment, string $number): void
    {
        $this->seedForFragments();

        $found = $this->reader->page(self::fragment($fragment), null, 10);

        self::assertSame([$number], array_map(static fn (ListedOrder $order): string => $order->number, $found));
        self::assertSame(1, $this->reader->count(self::fragment($fragment))->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function patternCharacters(): iterable
    {
        yield 'only percent signs' => ['%%%'];
        yield 'only underscores' => ['___'];
        yield 'a percent sign between letters that matches nothing literally' => ['a%z'];
        yield 'an underscore for any character' => ['a_c'];
        yield 'a backslash before a percent sign' => ['\\%x'];
    }

    #[DataProvider('patternCharacters')]
    public function testPercentUnderscoreAndBackslashAreNoPattern(string $fragment): void
    {
        $this->seedForFragments();
        $this->order('paid', '2026-11-06 10:00:00', 'R-100905', email: 'abc@example.com', fullName: 'Aaa Czzz');

        self::assertSame([], $this->reader->page(self::fragment($fragment), null, 10));
        self::assertSame(0, $this->reader->count(self::fragment($fragment))->value);
    }

    public function testAFragmentJoiningTheEndOfOneFieldToTheStartOfTheNextFindsNothing(): void
    {
        $this->order('paid', '2026-11-05 10:00:00', 'R-100123', email: 'anna.nowak@example.com', fullName: 'Anna Nowak');

        foreach (['R-100123anna', 'R-100123 anna', 'R-100123@anna', 'example.comAnna', 'example.com Anna', 'ak@example.comAnna'] as $joined) {
            self::assertSame([], $this->reader->page(self::fragment($joined), null, 10), $joined);
        }
        self::assertCount(1, $this->reader->page(self::fragment('anna.nowak@example.com'), null, 10));
        self::assertCount(1, $this->reader->page(self::fragment('Anna Nowak'), null, 10));
    }

    public function testAFragmentCombinesWithTheStatusTheDateSortAndTheRange(): void
    {
        $oldest = $this->order('paid', '2026-11-03 10:00:00', 'R-100001', fullName: 'Jan Kowalski');
        $middle = $this->order('paid', '2026-11-04 10:00:00', 'R-100002', fullName: 'Jan Kowalski');
        $this->order('shipped', '2026-11-04 11:00:00', 'R-100003', fullName: 'Jan Kowalski');
        $this->order('paid', '2026-11-04 12:00:00', 'R-100004', fullName: 'Anna Nowak');
        $newest = $this->order('paid', '2026-11-05 10:00:00', 'R-100005', fullName: 'Jan Kowalski');
        $paid = static fn (BackofficeOrderSort $sort, ?string $from = null, ?string $before = null): BackofficeOrderFilter => new BackofficeOrderFilter(
            OrderStatus::Paid, false, null, null, $sort, 'kowalski', null === $from ? null : new \DateTimeImmutable($from), null === $before ? null : new \DateTimeImmutable($before),
        );

        self::assertSame([$newest, $middle, $oldest], $this->ids($this->reader->page($paid(BackofficeOrderSort::PlacedDescending), null, 10)));
        self::assertSame([$oldest, $middle, $newest], $this->ids($this->reader->page($paid(BackofficeOrderSort::PlacedAscending), null, 10)));
        self::assertSame([$middle], $this->ids($this->reader->page($paid(BackofficeOrderSort::PlacedDescending, '2026-11-04 00:00:00+00:00', '2026-11-05 00:00:00+00:00'), null, 10)));
        self::assertSame(1, $this->reader->count($paid(BackofficeOrderSort::PlacedDescending, '2026-11-04 00:00:00+00:00', '2026-11-05 00:00:00+00:00'))->value);
    }

    public function testTheRangeOfDaysIncludesTheFirstInstantAndExcludesTheInstantBefore(): void
    {
        $before = $this->order('paid', '2026-03-28 22:59:59', 'R-100001');
        $first = $this->order('paid', '2026-03-28 23:00:00', 'R-100002');
        $last = $this->order('paid', '2026-03-29 21:59:59', 'R-100003');
        $after = $this->order('paid', '2026-03-29 22:00:00', 'R-100004');
        $from = new \DateTimeImmutable('2026-03-28 23:00:00+00:00');
        $until = new \DateTimeImmutable('2026-03-29 22:00:00+00:00');

        self::assertSame([$last, $first], $this->ids($this->reader->page(new BackofficeOrderFilter(null, false, null, null, null, null, $from, $until), null, 10)));
        self::assertSame([$after, $last, $first], $this->ids($this->reader->page(new BackofficeOrderFilter(null, false, null, null, null, null, $from), null, 10)));
        self::assertSame([$last, $first, $before], $this->ids($this->reader->page(new BackofficeOrderFilter(null, false, null, null, null, null, null, $until), null, 10)));
        self::assertSame(2, $this->reader->count(new BackofficeOrderFilter(null, false, null, null, null, null, $from, $until))->value);
    }

    public function testAFragmentAndARangePageThroughWithTheCursor(): void
    {
        $expected = [];
        foreach ([10, 11, 11, 12, 13] as $n => $hour) {
            $expected[] = $this->order('paid', \sprintf('2026-11-05 %02d:00:00', $hour), 'R-'.(100_001 + $n), fullName: 'Jan Kowalski');
        }
        $this->order('paid', '2026-11-06 10:00:00', 'R-100099', fullName: 'Jan Kowalski');
        $filter = new BackofficeOrderFilter(null, false, null, null, null, 'kowal', new \DateTimeImmutable('2026-11-05 00:00:00+00:00'), new \DateTimeImmutable('2026-11-06 00:00:00+00:00'));

        $seen = $this->pageThrough($filter, 2);

        self::assertEqualsCanonicalizing($expected, $seen);
        self::assertCount(5, array_unique($seen));
    }

    /**
     * Four orders whose number, email and name each hold a fragment the others do not.
     */
    private function seedForFragments(): void
    {
        $this->order('paid', '2026-11-05 10:00:00', 'R-100123', email: 'anna.nowak@example.com', fullName: 'Anna Nowak');
        $this->order('paid', '2026-11-05 11:00:00', 'R-100456', email: 'Jan@Gmail.com', fullName: 'Łukasz Żółć');
        $this->order('paid', '2026-11-05 12:00:00', 'R-100789', email: 'zofia@wp.pl', fullName: 'Zofia Wiśniewska');
        $this->order('paid', '2026-11-05 13:00:00', 'R-100901', email: 'rabat@example.com', fullName: 'Rabat 50% Kowalczyk');
        $this->order('paid', '2026-11-05 14:00:00', 'R-100902', email: 'x@example.com', fullName: 'Ola a_b');
        $this->order('paid', '2026-11-05 15:00:00', 'R-100903', email: 'y@example.com', fullName: 'Ola c\\d');
        $this->order('paid', '2026-11-05 16:00:00', 'R-100904', email: 'z@example.com', fullName: "Ola O'Brien");
    }

    private static function fragment(string $fragment): BackofficeOrderFilter
    {
        return new BackofficeOrderFilter(null, false, null, null, null, $fragment);
    }

    public function testPagingNewestFirstVisitsEveryOrderOnce(): void
    {
        $expected = [];
        foreach ([10, 11, 11, 11, 12, 13, 13] as $n => $hour) {
            $expected[] = $this->order('paid', \sprintf('2026-11-05 %02d:00:00', $hour), 'R-'.(100_001 + $n));
        }

        $seen = $this->pageThrough(self::all(), 2);

        self::assertCount(7, $seen);
        self::assertSame($this->ids($this->reader->page(self::all(), null, 10)), $seen, 'the order of one page of all');
        self::assertEqualsCanonicalizing($expected, $seen);
    }

    public function testPagingThePendingRefundsVisitsEachOnce(): void
    {
        $expected = [];
        foreach (['2026-11-15', '2026-11-15', '2026-11-16', '2026-11-15', '2026-11-17'] as $n => $due) {
            $expected[] = $this->order($n % 2 ? 'returned' : 'cancelled_by_staff', '2026-11-01 10:00:00', 'R-'.(100_001 + $n), refundDueAt: $due.' 10:00:00+00:00');
        }

        $seen = $this->pageThrough(self::pendingRefunds(), 2);

        self::assertSame($this->ids($this->reader->page(self::pendingRefunds(), null, 10)), $seen);
        self::assertEqualsCanonicalizing($expected, $seen);
    }

    public function testCountsTheOrdersOfTheSameFilterAsThePageHas(): void
    {
        $this->order('paid', '2026-11-05 10:00:00', 'R-100001', email: 'jan.kowalski@example.com');
        $this->order('returned', '2026-11-05 11:00:00', 'R-100002', email: 'Jan.Kowalski@Example.com', refundDueAt: '2026-11-19 11:00:00+00:00');
        $this->order('returned', '2026-11-05 12:00:00', 'R-100003', refundDueAt: '2026-11-19 12:00:00+00:00', refundCompletedAt: '2026-11-10 08:30:00+00:00');
        $this->order('shipped', '2026-11-05 13:00:00', 'R-100004');

        $filters = [
            'no filter' => [self::all(), 4],
            'a status' => [new BackofficeOrderFilter(OrderStatus::Returned, false, null, null), 2],
            'the pending refunds' => [new BackofficeOrderFilter(null, true, null, null), 1],
            'the pending refunds of a status' => [new BackofficeOrderFilter(OrderStatus::Paid, true, null, null), 0],
            'a number' => [new BackofficeOrderFilter(null, false, 'R-100004', null), 1],
            'an email in any letter case' => [new BackofficeOrderFilter(null, false, null, 'JAN.KOWALSKI@example.com'), 2],
            'an email and a status' => [new BackofficeOrderFilter(OrderStatus::Paid, false, null, 'jan.kowalski@example.com'), 1],
        ];
        foreach ($filters as $name => [$filter, $expected]) {
            $count = $this->reader->count($filter);

            self::assertSame([$expected, false], [$count->value, $count->capped], $name);
            self::assertCount($expected, $this->reader->page($filter, null, 10), $name.': the count is what the pages hold');
        }
    }

    public function testALimitBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->reader->page(self::all(), null, 0);
    }

    /**
     * @return list<string> the ids of every page, read with the cursor after the last order of the previous one
     */
    private function pageThrough(BackofficeOrderFilter $filter, int $limit): array
    {
        $seen = [];
        $after = null;
        for ($guard = 0; $guard < 20; ++$guard) {
            $page = $this->reader->page($filter, $after, $limit);
            $seen = [...$seen, ...$this->ids($page)];
            if (\count($page) < $limit) {
                return $seen;
            }
            $after = BackofficeOrderCursor::decode(BackofficeOrderCursor::after($page[$limit - 1], $filter->sort)->encode(), $filter->sort);
        }
        self::fail('The paging does not end.');
    }

    /**
     * @param 'new'|'paid'|'shipped'|'delivered'|'cancelled'|'cancelled_by_staff'|'returned' $status
     * @param list<int>                                                                      $quantities
     */
    private function order(string $status, string $placedAt, string $number, string $email = 'jan@example.com', array $quantities = [1], ?string $refundDueAt = null, ?string $refundCompletedAt = null, ?string $orderId = null, string $fullName = 'Jan Kowalski'): string
    {
        return BackofficeOrderFixture::order($this->connection, $status, $placedAt.'+00:00', $number, $email, $quantities, $refundDueAt, $refundCompletedAt, $orderId, null, $fullName);
    }

    /**
     * @param list<ListedOrder> $orders
     *
     * @return list<string>
     */
    private function ids(array $orders): array
    {
        return array_map(static fn (ListedOrder $order): string => $order->orderId, $orders);
    }

    private static function all(): BackofficeOrderFilter
    {
        return new BackofficeOrderFilter(null, false, null, null);
    }

    private static function pendingRefunds(): BackofficeOrderFilter
    {
        return new BackofficeOrderFilter(null, true, null, null);
    }
}
