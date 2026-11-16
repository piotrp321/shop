<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Port\ListedCustomer;
use App\Ordering\Application\Query\BackofficeCustomerCursor;
use App\Ordering\Application\Query\BackofficeCustomerListItem;
use App\Ordering\Application\Query\BackofficeCustomerSort;
use App\Ordering\Application\Query\InvalidBackofficeCustomerCursor;
use App\Ordering\Application\Query\InvalidBackofficeCustomerList;
use App\Ordering\Application\Query\ListBackofficeCustomers;
use App\Ordering\Application\Query\ListBackofficeCustomersHandler;
use App\Shared\Domain\BoundedCount;
use App\Tests\Unit\Ordering\InMemoryBackofficeCustomerReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The customer list with an in-memory reader: one customer more than the page tells there is a next page, the first
 * page counts the matching customers (only when there is a next page), the search is a trimmed fragment and only with
 * the sort by the last order, and a cursor of another sort is refused.
 */
final class ListBackofficeCustomersHandlerTest extends TestCase
{
    public function testAPageOfTheNewestLastOrdersWithItsCursorAndTotal(): void
    {
        $reader = new InMemoryBackofficeCustomerReader(self::customers(3), total: new BoundedCount(3, false));

        $list = new ListBackofficeCustomersHandler($reader)(new ListBackofficeCustomers(null, null, null, 2));

        self::assertEquals([
            new BackofficeCustomerListItem('kc-1', 'k1@example.com', 'Klient 1', 1, 1_000, 'PLN', new \DateTimeImmutable('2026-11-01 10:00:00+00:00')),
            new BackofficeCustomerListItem('kc-2', 'k2@example.com', 'Klient 2', 2, 2_000, 'PLN', new \DateTimeImmutable('2026-11-02 10:00:00+00:00')),
        ], $list->items);
        self::assertEquals(new BoundedCount(3, false), $list->total);
        self::assertNotNull($list->nextCursor);
        self::assertSame('kc-2', BackofficeCustomerCursor::decode($list->nextCursor, BackofficeCustomerSort::LastOrderDescending)->customerId);
        self::assertSame([['page', null, '-lastOrderAt', null, 3]], $reader->pages, 'one customer more than the page');
    }

    public function testTheNextPageHasNoTotalAndTheLastPageNoCursor(): void
    {
        $reader = new InMemoryBackofficeCustomerReader(self::customers(3));
        $first = new ListBackofficeCustomersHandler($reader)(new ListBackofficeCustomers(null, BackofficeCustomerSort::Name, null, 2));
        self::assertNotNull($first->nextCursor);

        $second = new ListBackofficeCustomersHandler($reader)(new ListBackofficeCustomers(null, BackofficeCustomerSort::Name, $first->nextCursor, 2));

        self::assertSame(['kc-3'], array_map(static fn (BackofficeCustomerListItem $item): string => $item->customerId, $second->items));
        self::assertNull($second->nextCursor);
        self::assertNull($second->total);
        self::assertSame([null], $reader->counts, 'only the first page with a next page counts');
    }

    public function testAFirstPageWithoutANextPageIsItsOwnTotal(): void
    {
        $reader = new InMemoryBackofficeCustomerReader(self::customers(2));

        $list = new ListBackofficeCustomersHandler($reader)(new ListBackofficeCustomers(null, BackofficeCustomerSort::PaidGrossDescending, null, 25));

        self::assertEquals(new BoundedCount(2, false), $list->total);
        self::assertNull($list->nextCursor);
        self::assertSame([], $reader->counts);
    }

    public function testTheSearchIsATrimmedFragmentSortedByTheLastOrder(): void
    {
        $reader = new InMemoryBackofficeCustomerReader(self::customers(3));

        $list = new ListBackofficeCustomersHandler($reader)(new ListBackofficeCustomers("\u{00A0}k2@ex ", BackofficeCustomerSort::LastOrderDescending, null, 25));

        self::assertSame(['kc-2'], array_map(static fn (BackofficeCustomerListItem $item): string => $item->customerId, $list->items));
        self::assertSame([['page', 'k2@ex', '-lastOrderAt', null, 26]], $reader->pages);
    }

    /**
     * @return iterable<string, array{BackofficeCustomerSort}>
     */
    public static function otherSorts(): iterable
    {
        yield 'name' => [BackofficeCustomerSort::Name];
        yield 'orders' => [BackofficeCustomerSort::OrderCountDescending];
        yield 'value' => [BackofficeCustomerSort::PaidGrossDescending];
    }

    #[DataProvider('otherSorts')]
    public function testTheSearchWithAnotherSortIsRefused(BackofficeCustomerSort $sort): void
    {
        $reader = new InMemoryBackofficeCustomerReader(self::customers(1));

        try {
            new ListBackofficeCustomersHandler($reader)(new ListBackofficeCustomers('klient', $sort, null, 25));
            self::fail('Expected the search with another sort to be refused.');
        } catch (InvalidBackofficeCustomerList) {
            self::assertSame([], $reader->pages);
        }
    }

    public function testATooShortSearchIsRefused(): void
    {
        $this->expectException(InvalidBackofficeCustomerList::class);

        new ListBackofficeCustomersHandler(new InMemoryBackofficeCustomerReader())(new ListBackofficeCustomers(' k ', null, null, 25));
    }

    public function testACursorOfAnotherSortIsRefused(): void
    {
        $reader = new InMemoryBackofficeCustomerReader(self::customers(3));
        $first = new ListBackofficeCustomersHandler($reader)(new ListBackofficeCustomers(null, BackofficeCustomerSort::Name, null, 1));
        self::assertNotNull($first->nextCursor);

        $this->expectException(InvalidBackofficeCustomerCursor::class);
        new ListBackofficeCustomersHandler($reader)(new ListBackofficeCustomers(null, BackofficeCustomerSort::OrderCountDescending, $first->nextCursor, 1));
    }

    /**
     * @return list<ListedCustomer>
     */
    private static function customers(int $count): array
    {
        $customers = [];
        for ($i = 1; $i <= $count; ++$i) {
            $customers[] = new ListedCustomer('kc-'.$i, "k{$i}@example.com", 'Klient '.$i, $i, $i * 1_000, new \DateTimeImmutable("2026-11-0{$i} 10:00:00+00:00"), 'klient '.$i);
        }

        return $customers;
    }
}
