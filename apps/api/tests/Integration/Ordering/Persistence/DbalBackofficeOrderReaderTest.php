<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Persistence;

use App\Ordering\Application\Port\BackofficeOrderFilter;
use App\Ordering\Application\Port\ListedOrder;
use App\Ordering\Application\Query\BackofficeOrderCursor;
use App\Ordering\Domain\OrderStatus;
use App\Ordering\Infrastructure\Persistence\Doctrine\DbalBackofficeOrderReader;
use App\Tests\Integration\Ordering\BackofficeOrderFixture;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalBackofficeOrderReader on shop_test (rolled back after each test, with no other order in the transaction): the
 * filters, the two sorts with their keyset cursors, and what a listed order carries.
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
        $this->reader = new DbalBackofficeOrderReader($this->connection);
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
            $after = BackofficeOrderCursor::decode(BackofficeOrderCursor::after($page[$limit - 1], $filter->pendingRefunds)->encode(), $filter->pendingRefunds);
        }
        self::fail('The paging does not end.');
    }

    /**
     * @param 'new'|'paid'|'shipped'|'delivered'|'cancelled'|'cancelled_by_staff'|'returned' $status
     * @param list<int>                                                                      $quantities
     */
    private function order(string $status, string $placedAt, string $number, string $email = 'jan@example.com', array $quantities = [1], ?string $refundDueAt = null, ?string $refundCompletedAt = null, ?string $orderId = null): string
    {
        return BackofficeOrderFixture::order($this->connection, $status, $placedAt.'+00:00', $number, $email, $quantities, $refundDueAt, $refundCompletedAt, $orderId);
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
