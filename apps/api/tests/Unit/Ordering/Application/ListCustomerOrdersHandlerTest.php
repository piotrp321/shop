<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Port\CustomerListedOrder;
use App\Ordering\Application\Port\ListedOrder;
use App\Ordering\Application\Query\BackofficeOrderCursor;
use App\Ordering\Application\Query\CustomerOrderList;
use App\Ordering\Application\Query\InvalidBackofficeOrderCursor;
use App\Ordering\Application\Query\ListCustomerOrders;
use App\Ordering\Application\Query\ListCustomerOrdersHandler;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use App\Tests\Unit\Ordering\InMemoryCustomerOrderReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ListCustomerOrders, the order history of a customer: the customer and the decoded cursor go to the reader with one
 * order more than the page, the next cursor, and the payment deadline passed against the clock.
 */
final class ListCustomerOrdersHandlerTest extends TestCase
{
    private const string CUSTOMER = 'kc-sub-1';
    private const string DEADLINE = '2026-11-05 12:30:00+00:00';

    private InMemoryCustomerOrderReader $reader;
    private FixedOrderingClock $clock;

    protected function setUp(): void
    {
        $this->reader = new InMemoryCustomerOrderReader();
        $this->clock = new FixedOrderingClock(new \DateTimeImmutable(self::DEADLINE));
    }

    public function testTheCustomerGoesToTheReaderWithOneOrderMoreThanThePage(): void
    {
        $this->reader->orders = [self::listed(1)];

        $list = $this->list(new ListCustomerOrders(self::CUSTOMER, null, 10));

        self::assertEquals([[self::CUSTOMER, null, 11]], $this->reader->pages);
        self::assertNull($list->nextCursor, 'the last page');
        self::assertCount(1, $list->items);
        $item = $list->items[0];
        self::assertSame([self::id(1), 'R-100001', 'paid', 26_600, 'PLN', 3], [$item->orderId, $item->number, $item->status, $item->totalGross, $item->currency, $item->itemCount]);
        self::assertEquals(new \DateTimeImmutable('2026-11-05 12:00:01+00:00'), $item->placedAt);
        self::assertEquals(new \DateTimeImmutable(self::DEADLINE), $item->paymentDeadline);
    }

    public function testAFullPageWithAnOrderMoreHasTheCursorAfterItsLastOrder(): void
    {
        $this->reader->orders = [self::listed(3), self::listed(2), self::listed(1)];

        $list = $this->list(new ListCustomerOrders(self::CUSTOMER, null, 2));

        self::assertSame([self::id(3), self::id(2)], array_map(static fn ($item): string => $item->orderId, $list->items));
        self::assertNotNull($list->nextCursor);
        $cursor = BackofficeOrderCursor::decode($list->nextCursor, false);
        self::assertSame(self::id(2), $cursor->id);
        self::assertEquals(new \DateTimeImmutable('2026-11-05 12:00:02+00:00'), $cursor->at);
    }

    public function testAPageOfExactlyTheSizeIsTheLastOne(): void
    {
        $this->reader->orders = [self::listed(2), self::listed(1)];

        self::assertNull($this->list(new ListCustomerOrders(self::CUSTOMER, null, 2))->nextCursor);
    }

    public function testTheCursorIsDecodedForTheReader(): void
    {
        $this->reader->orders = [self::listed(3), self::listed(2), self::listed(1)];
        $first = $this->list(new ListCustomerOrders(self::CUSTOMER, null, 2));
        self::assertNotNull($first->nextCursor);

        $this->list(new ListCustomerOrders(self::CUSTOMER, $first->nextCursor, 2));

        self::assertCount(2, $this->reader->pages, 'the first page and this one');
        [$customer, $after, $limit] = $this->reader->pages[1];
        self::assertSame([self::CUSTOMER, self::id(2), 3], [$customer, $after?->id, $limit]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badCursors(): iterable
    {
        yield 'not base64url' => ['garbage!'];
        yield 'another shape' => ['aGVsbG8'];
        yield 'a cursor of the pending refunds of the panel' => [BackofficeOrderCursor::after(self::listedForRefund(), true)->encode()];
    }

    #[DataProvider('badCursors')]
    public function testACursorNobodyIssuedForThisListIsRefusedWithoutAReadOfTheDatabase(string $cursor): void
    {
        $this->expectException(InvalidBackofficeOrderCursor::class);

        try {
            $this->list(new ListCustomerOrders(self::CUSTOMER, $cursor, 10));
        } finally {
            self::assertSame([], $this->reader->pages);
        }
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function deadlines(): iterable
    {
        yield 'new, a second before the deadline' => ['new', '2026-11-05 12:29:59+00:00', false];
        yield 'new, at the deadline (no payment can be started)' => ['new', self::DEADLINE, true];
        yield 'new, after the deadline' => ['new', '2026-11-05 12:30:01+00:00', true];
        yield 'paid, after the deadline' => ['paid', '2026-11-06 12:00:00+00:00', false];
        yield 'cancelled, after the deadline' => ['cancelled', '2026-11-06 12:00:00+00:00', false];
    }

    #[DataProvider('deadlines')]
    public function testThePaymentDeadlinePassedIsOnlyOfANewOrderAtOrPastItsDeadline(string $status, string $now, bool $passed): void
    {
        $this->reader->orders = [self::listed(1, $status)];
        $this->clock->now = new \DateTimeImmutable($now);

        self::assertSame($passed, $this->list(new ListCustomerOrders(self::CUSTOMER, null, 10))->items[0]->paymentDeadlinePassed);
    }

    private function list(ListCustomerOrders $query): CustomerOrderList
    {
        return new ListCustomerOrdersHandler($this->reader, $this->clock)($query);
    }

    private static function id(int $n): string
    {
        return \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $n);
    }

    /**
     * An order placed at 12:00:0n.
     */
    private static function listed(int $n, string $status = 'paid'): CustomerListedOrder
    {
        return new CustomerListedOrder(
            self::id($n),
            'R-'.(100_000 + $n),
            new \DateTimeImmutable(\sprintf('2026-11-05 12:00:%02d+00:00', $n)),
            $status,
            26_600,
            'PLN',
            3,
            new \DateTimeImmutable(self::DEADLINE),
        );
    }

    private static function listedForRefund(): ListedOrder
    {
        return new ListedOrder(
            self::id(1),
            'R-100001',
            new \DateTimeImmutable('2026-11-05 12:00:01+00:00'),
            'cancelled',
            'jan@example.com',
            'Jan Kowalski',
            26_600,
            'PLN',
            3,
            26_600,
            new \DateTimeImmutable('2026-11-19 12:00:00+00:00'),
            null,
        );
    }
}
