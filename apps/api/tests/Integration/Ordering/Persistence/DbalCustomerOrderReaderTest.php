<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Persistence;

use App\Ordering\Application\Port\CustomerListedOrder;
use App\Ordering\Application\Port\ListedOrder;
use App\Ordering\Application\Query\BackofficeOrderCursor;
use App\Ordering\Infrastructure\Persistence\Doctrine\DbalCustomerOrderReader;
use App\Tests\Integration\Ordering\BackofficeOrderFixture;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalCustomerOrderReader on shop_test (rolled back after each test, with no other order in the transaction): only the
 * orders of the customer, newest first with the keyset cursor, and what a listed order carries.
 */
final class DbalCustomerOrderReaderTest extends KernelTestCase
{
    private const string CUSTOMER = 'kc-sub-1';
    private const string OTHER = 'kc-sub-2';

    private Connection $connection;
    private DbalCustomerOrderReader $reader;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
        $this->reader = new DbalCustomerOrderReader($this->connection);
        BackofficeOrderFixture::noOrders($this->connection);
    }

    public function testOnlyTheOrdersOfTheCustomerNewestFirstThenByIdDescending(): void
    {
        $older = $this->order('paid', '2026-11-05 10:00:00', 'R-100001');
        $tieLow = $this->order('new', '2026-11-05 11:00:00', 'R-100002', orderId: '0192f0c4-7c1a-7c4e-9a6e-000000000001');
        $tieHigh = $this->order('shipped', '2026-11-05 11:00:00', 'R-100003', orderId: '0192f0c4-7c1a-7c4e-9a6e-000000000002');
        $this->order('paid', '2026-11-05 11:30:00', 'R-100004', customerId: self::OTHER);
        BackofficeOrderFixture::order($this->connection, 'paid', '2026-11-05 11:40:00+00:00', 'R-100005');
        $newest = $this->order('cancelled', '2026-11-05 12:00:00', 'R-100006');

        self::assertSame([$newest, $tieHigh, $tieLow, $older], $this->ids($this->reader->page(self::CUSTOMER, null, 10)));
        self::assertSame([$newest, $tieHigh], $this->ids($this->reader->page(self::CUSTOMER, null, 2)), 'at most the limit');
        self::assertSame([], $this->reader->page('nobody', null, 10));
        self::assertSame([], $this->reader->page('', null, 10), 'an empty id is nobody, not the guests');
    }

    public function testAListedOrderCarriesItsDataUnitsAndDeadline(): void
    {
        $orderId = $this->order('new', '2026-11-05 10:00:00', 'R-100042', quantities: [2, 3]);

        [$order] = $this->reader->page(self::CUSTOMER, null, 10);

        self::assertEquals(
            new CustomerListedOrder($orderId, 'R-100042', new \DateTimeImmutable('2026-11-05 10:00:00+00:00'), 'new', 5_000, 'PLN', 5, new \DateTimeImmutable('2026-11-05 10:30:00+00:00')),
            $order,
        );
    }

    public function testKeysetPagingVisitsEveryOrderOnceAcrossEqualMoments(): void
    {
        $expected = [];
        foreach (['2026-11-05 10:00:00', '2026-11-05 11:00:00', '2026-11-05 11:00:00', '2026-11-05 11:00:00', '2026-11-05 12:00:00'] as $n => $placedAt) {
            $expected[] = $this->order('paid', $placedAt, 'R-'.(100_001 + $n));
        }
        $this->order('paid', '2026-11-05 11:00:00', 'R-100099', customerId: self::OTHER);

        $seen = [];
        $after = null;
        for ($guard = 0; $guard < 10; ++$guard) {
            $page = $this->reader->page(self::CUSTOMER, $after, 2);
            $seen = [...$seen, ...$this->ids($page)];
            if (\count($page) < 2) {
                break;
            }
            $after = BackofficeOrderCursor::decode(BackofficeOrderCursor::placedAfter($page[1]->placedAt, $page[1]->orderId)->encode(), false);
        }

        self::assertSame($this->ids($this->reader->page(self::CUSTOMER, null, 100)), $seen, 'the pages together are the one page');
        self::assertEqualsCanonicalizing($expected, $seen);
        self::assertCount(5, array_unique($seen));
    }

    public function testACursorOfTheSortOfThePendingRefundsIsRefused(): void
    {
        $order = $this->order('returned', '2026-11-05 10:00:00', 'R-100001', refundDueAt: '2026-11-19 10:00:00+00:00');
        $cursor = BackofficeOrderCursor::decode(BackofficeOrderCursor::after(new ListedOrder($order, 'R-100001', new \DateTimeImmutable('2026-11-05 10:00:00+00:00'), 'returned', 'jan@example.com', 'Jan', 1_000, 'PLN', 1, 1_000, new \DateTimeImmutable('2026-11-19 10:00:00+00:00'), null), true)->encode(), true);

        $this->expectException(\InvalidArgumentException::class);

        $this->reader->page(self::CUSTOMER, $cursor, 10);
    }

    /**
     * @param 'new'|'paid'|'shipped'|'delivered'|'cancelled'|'cancelled_by_staff'|'returned' $status
     * @param list<int>                                                                      $quantities
     */
    private function order(string $status, string $placedAt, string $number, array $quantities = [1], ?string $refundDueAt = null, ?string $orderId = null, string $customerId = self::CUSTOMER): string
    {
        return BackofficeOrderFixture::order($this->connection, $status, $placedAt.'+00:00', $number, 'jan@example.com', $quantities, $refundDueAt, null, $orderId, $customerId);
    }

    /**
     * @param list<CustomerListedOrder> $orders
     *
     * @return list<string>
     */
    private function ids(array $orders): array
    {
        return array_map(static fn (CustomerListedOrder $order): string => $order->orderId, $orders);
    }
}
