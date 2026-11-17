<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Persistence;

use App\Ordering\Application\Port\CustomerSummary;
use App\Ordering\Application\Port\ListedCustomer;
use App\Ordering\Application\Query\BackofficeCustomerCursor;
use App\Ordering\Application\Query\BackofficeCustomerSort;
use App\Ordering\Application\View\ShippingAddressView;
use App\Ordering\Infrastructure\Persistence\Doctrine\DbalCustomerReader;
use App\Shared\Domain\BoundedCount;
use App\Shared\Infrastructure\Persistence\BoundedCounter;
use App\Tests\Integration\Ordering\OrderingFixture;
use App\Tests\Support\RecordingConnection;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The customer summaries and the addresses of their orders read for the panel on PostgreSQL, in the transaction DAMA
 * rolls back after each test: every sort pages with its cursor through ties (the customer id ascending breaks them),
 * the fragment is found in the email or the name in any letter case with `%` and `_` literal, the count is bounded,
 * and the addresses are the distinct ones of the last orders of the customer, the one used last first.
 */
final class DbalCustomerReaderTest extends KernelTestCase
{
    private Connection $connection;
    private DbalCustomerReader $reader;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->reader = new DbalCustomerReader($connection, new BoundedCounter($connection));
        $this->connection->executeStatement('DELETE FROM ordering_customer');
    }

    /**
     * @return iterable<string, array{BackofficeCustomerSort, list<string>}>
     */
    public static function sorts(): iterable
    {
        yield 'the newest last order first' => [BackofficeCustomerSort::LastOrderDescending, ['kc-c', 'kc-a', 'kc-b', 'kc-d', 'kc-e']];
        yield 'by name in any letter case' => [BackofficeCustomerSort::Name, ['kc-b', 'kc-c', 'kc-e', 'kc-a', 'kc-d']];
        yield 'the most orders first' => [BackofficeCustomerSort::OrderCountDescending, ['kc-d', 'kc-a', 'kc-c', 'kc-b', 'kc-e']];
        yield 'the highest paid value first' => [BackofficeCustomerSort::PaidGrossDescending, ['kc-b', 'kc-d', 'kc-e', 'kc-a', 'kc-c']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('sorts')]
    public function testEverySortPagesWithItsCursorThroughTies(BackofficeCustomerSort $sort, array $expected): void
    {
        $this->customers();

        $ids = [];
        $after = null;
        do {
            $page = $this->reader->page(null, $sort, $after, 2);
            foreach ($page as $customer) {
                $ids[] = $customer->customerId;
            }
            $after = 2 === \count($page) ? BackofficeCustomerCursor::after($page[1], $sort) : null;
        } while (null !== $after);

        self::assertSame($expected, $ids);
    }

    /**
     * @return iterable<string, array{BackofficeCustomerSort, string}>
     */
    public static function indexedPages(): iterable
    {
        yield 'the newest last order first' => [BackofficeCustomerSort::LastOrderDescending, 'ordering_customer_last_order_at_idx'];
        yield 'by name' => [BackofficeCustomerSort::Name, 'ordering_customer_name_idx'];
        yield 'the most orders first' => [BackofficeCustomerSort::OrderCountDescending, 'ordering_customer_order_count_idx'];
        yield 'the highest paid value first' => [BackofficeCustomerSort::PaidGrossDescending, 'ordering_customer_paid_gross_idx'];
    }

    /**
     * The plan of the first page and of a page after a cursor of every sort (sequential scans and explicit sorts made
     * unattractive, as in the plan tests of the other lists): the index of the sort delivers the order.
     */
    #[DataProvider('indexedPages')]
    public function testEveryPageOfEverySortIsServedByItsIndexWithoutASort(BackofficeCustomerSort $sort, string $index): void
    {
        $this->customers();
        $recording = RecordingConnection::like($this->connection);
        $reader = new DbalCustomerReader($recording, new BoundedCounter($recording));

        $first = $reader->page(null, $sort, null, 2);
        $firstPlan = $recording->planOfTheLastQuery($this->connection);
        $reader->page(null, $sort, BackofficeCustomerCursor::after($first[1], $sort), 2);
        $nextPlan = $recording->planOfTheLastQuery($this->connection);

        foreach (['the first page' => $firstPlan, 'a page after a cursor' => $nextPlan] as $label => $plan) {
            self::assertStringContainsString($index, $plan, $label."\n".$plan);
            self::assertStringNotContainsString('Sort', $plan, $label."\n".$plan);
            self::assertStringNotContainsString('Seq Scan', $plan, $label."\n".$plan);
        }
    }

    public function testARowHasTheListedValues(): void
    {
        $this->summary('kc-a', 'anna@example.com', 'Anna Nowak', 3, 24_600, '2026-11-02 10:00:00+00');

        self::assertEquals(
            [new ListedCustomer('kc-a', 'anna@example.com', 'Anna Nowak', 3, 24_600, new \DateTimeImmutable('2026-11-02 10:00:00+00:00'), 'anna nowak')],
            $this->reader->page(null, BackofficeCustomerSort::Name, null, 10),
        );
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function fragments(): iterable
    {
        yield 'a part of the email' => ['nowak@', ['kc-a']];
        yield 'a part of the name in another letter case' => ['NOWAK', ['kc-c', 'kc-a']];
        yield 'Polish letters in another letter case' => ['łUCJ', ['kc-d']];
        yield 'a percent sign as it is' => ['50%', ['kc-e']];
        yield 'an underscore as it is' => ['a_b', ['kc-b']];
        yield 'no customer' => ['zzz', []];
        yield 'not across the email and the name' => ['.comAnna', []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('fragments')]
    public function testTheFragmentIsFoundInTheEmailOrTheName(string $fragment, array $expected): void
    {
        $this->summary('kc-a', 'anna.nowak@example.com', 'Anna Nowak', 1, 0, '2026-11-01 10:00:00+00');
        $this->summary('kc-b', 'a_b@example.com', 'Bartek Lis', 1, 0, '2026-11-02 10:00:00+00');
        $this->summary('kc-c', 'ewa@example.com', 'Ewa Nowakowska', 1, 0, '2026-11-03 10:00:00+00');
        $this->summary('kc-d', 'lucja@example.com', 'Łucja Gęś', 1, 0, '2026-11-04 10:00:00+00');
        $this->summary('kc-e', 'promo50%@example.com', 'Ola Kot', 1, 0, '2026-10-01 10:00:00+00');
        $this->summary('kc-f', 'axb@example.com', 'Piotr Pies', 1, 0, '2026-10-02 10:00:00+00');

        $ids = array_map(static fn (ListedCustomer $customer): string => $customer->customerId, $this->reader->page($fragment, BackofficeCustomerSort::LastOrderDescending, null, 10));

        self::assertSame($expected, $ids);
        self::assertEquals(new BoundedCount(\count($expected), false), $this->reader->count($fragment));
    }

    public function testTheCountIsBounded(): void
    {
        $this->customers();

        self::assertEquals(new BoundedCount(5, false), $this->reader->count(null));
        self::assertEquals(new BoundedCount(3, true), $this->reader->count(null, 3));
    }

    public function testFindsTheSummaryOfACustomer(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO ordering_customer (customer_id, email, full_name, phone, order_count, paid_count, paid_gross, first_order_at, last_order_at, last_order_id)
             VALUES ('kc-a', 'anna@example.com', 'Anna Nowak', '+48600100200', 3, 2, 24600, '2026-11-01 10:00:00+00', '2026-11-03 10:00:00+00', gen_random_uuid())",
        );

        self::assertEquals(
            new CustomerSummary('kc-a', 'anna@example.com', 'Anna Nowak', '+48600100200', 3, 2, 24_600, new \DateTimeImmutable('2026-11-01 10:00:00+00:00'), new \DateTimeImmutable('2026-11-03 10:00:00+00:00')),
            $this->reader->find('kc-a'),
        );
        self::assertNull($this->reader->find('kc-b'));
    }

    public function testTheAddressesAreTheDistinctOnesOfTheLastOrdersUsedLastFirst(): void
    {
        OrderingFixture::order($this->connection, customerId: 'kc-a', placedAt: '2026-11-01 10:00:00+00:00', street: 'Długa 1');
        OrderingFixture::order($this->connection, customerId: 'kc-a', placedAt: '2026-11-02 10:00:00+00:00', street: 'Krótka 2');
        OrderingFixture::order($this->connection, customerId: 'kc-a', placedAt: '2026-11-03 10:00:00+00:00', street: 'Długa 1');
        OrderingFixture::order($this->connection, customerId: 'kc-a', placedAt: '2026-11-04 10:00:00+00:00', street: 'Polna 3');
        // Older than the last three orders: out of them.
        OrderingFixture::order($this->connection, customerId: 'kc-a', placedAt: '2026-10-01 10:00:00+00:00', street: 'Stara 9');
        // Of another customer and of a guest with the same email.
        OrderingFixture::order($this->connection, customerId: 'kc-b', placedAt: '2026-11-05 10:00:00+00:00', street: 'Obca 7');
        OrderingFixture::order($this->connection, placedAt: '2026-11-05 10:00:00+00:00', street: 'Gościnna 5');

        self::assertEquals([self::address('Polna 3'), self::address('Długa 1'), self::address('Krótka 2')], $this->reader->addresses('kc-a', 4, 10));
        self::assertEquals([self::address('Polna 3'), self::address('Długa 1')], $this->reader->addresses('kc-a', 4, 2));
        self::assertEquals([self::address('Polna 3'), self::address('Długa 1')], $this->reader->addresses('kc-a', 2, 10));
        self::assertSame([], $this->reader->addresses('kc-c', 50, 10));
    }

    private static function address(string $street): ShippingAddressView
    {
        return new ShippingAddressView($street, '00-950', 'Warszawa', 'PL');
    }

    /**
     * Five customers with ties in every sort but the name.
     */
    private function customers(): void
    {
        $this->summary('kc-a', 'a@example.com', 'Natalia', 3, 1_000, '2026-11-02 10:00:00+00');
        $this->summary('kc-b', 'b@example.com', 'anna', 1, 9_000, '2026-11-02 10:00:00+00');
        $this->summary('kc-c', 'c@example.com', 'Bartek', 3, 0, '2026-11-03 10:00:00+00');
        $this->summary('kc-d', 'd@example.com', 'Zofia', 5, 5_000, '2026-11-01 10:00:00+00');
        $this->summary('kc-e', 'e@example.com', 'Ewa', 1, 5_000, '2026-11-01 10:00:00+00');
    }

    private function summary(string $customer, string $email, string $fullName, int $orders, int $paidGross, string $lastOrderAt): void
    {
        $this->connection->executeStatement(
            "INSERT INTO ordering_customer (customer_id, email, full_name, phone, order_count, paid_count, paid_gross, first_order_at, last_order_at, last_order_id)
             VALUES (?, ?, ?, '+48600100200', ?, 0, ?, '2026-01-01 00:00:00+00', ?, gen_random_uuid())",
            [$customer, $email, $fullName, $orders, $paidGross, $lastOrderAt],
        );
    }
}
