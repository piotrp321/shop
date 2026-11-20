<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Persistence;

use App\Ordering\Domain\EmailAddress;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderOwner;
use App\Ordering\Domain\UnpaidOrderIdentity;
use App\Ordering\Infrastructure\Persistence\Doctrine\DoctrineUnpaidOrderClaims;
use App\Tests\Integration\Ordering\OrderingFixture;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The claims of unpaid orders (ABUSE-LIMITS, D7) on shop_test, rolled back after each test: written and removed
 * through the record, counted by the address and by the identity among the claims whose expiry is after the moment
 * given, with the indexes of both counts, and gone with their order.
 */
final class DoctrineUnpaidOrderClaimsTest extends KernelTestCase
{
    private const string ADDRESS = '203.0.113.7';

    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private DoctrineUnpaidOrderClaims $claims;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->claims = new DoctrineUnpaidOrderClaims($entityManager);
        $this->now = new \DateTimeImmutable('2026-11-19 12:00:00+00:00');
        $this->connection->executeStatement('DELETE FROM ordering_unpaid_order_claim');
    }

    public function testAClaimIsWrittenWithItsKeysAndExpiry(): void
    {
        $order = $this->order();

        $this->claims->add($order, self::ADDRESS, self::guest('jan@example.com'), $this->now->modify('+35 minutes'));

        self::assertSame(
            [[$order->toString(), self::ADDRESS, 'email:jan@example.com', '2026-11-19 12:35:00']],
            $this->connection->fetchAllNumeric("SELECT order_id, address_key, identity_key, to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM ordering_unpaid_order_claim"),
        );
    }

    public function testTheCountsTakeOnlyTheClaimsOfTheKeyThatExpireAfterTheMoment(): void
    {
        $jan = self::guest('jan@example.com');
        $this->claims->add($this->order(), self::ADDRESS, $jan, $this->now->modify('+1 second'));
        $this->claims->add($this->order(), self::ADDRESS, self::guest('ewa@example.com'), $this->now->modify('+35 minutes'));
        $this->claims->add($this->order(), '198.51.100.1', $jan, $this->now->modify('+35 minutes'));
        $this->claims->add($this->order(), self::ADDRESS, $jan, $this->now);
        $this->claims->add($this->order(), self::ADDRESS, $jan, $this->now->modify('-1 minute'));
        $this->entityManager->clear();

        self::assertSame(2, $this->claims->countByAddress(self::ADDRESS, $this->now), 'an expiry at the moment does not count');
        self::assertSame(2, $this->claims->countByIdentity($jan, $this->now));
        self::assertSame(1, $this->claims->countByAddress('198.51.100.1', $this->now));
        self::assertSame(0, $this->claims->countByAddress('198.51.100.2', $this->now));
        self::assertSame(0, $this->claims->countByIdentity(UnpaidOrderIdentity::of(OrderOwner::customer('jan@example.com'), EmailAddress::fromString('jan@example.com')), $this->now));
    }

    public function testAClaimIsRemovedAndRemovingItAgainChangesNothing(): void
    {
        $order = $this->order();
        $other = $this->order();
        $this->claims->add($order, self::ADDRESS, self::guest('jan@example.com'), $this->now->modify('+35 minutes'));
        $this->claims->add($other, self::ADDRESS, self::guest('jan@example.com'), $this->now->modify('+35 minutes'));
        $this->entityManager->clear();

        $this->claims->remove($order);
        $this->claims->remove($order);
        $this->claims->remove($this->order());

        self::assertSame([$other->toString()], $this->connection->fetchFirstColumn('SELECT order_id FROM ordering_unpaid_order_claim'));
    }

    public function testAClaimAddedInTheSameUnitOfWorkIsRemoved(): void
    {
        $order = $this->order();
        $this->claims->add($order, self::ADDRESS, self::guest('jan@example.com'), $this->now->modify('+35 minutes'));

        $this->claims->remove($order);

        self::assertSame(0, OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM ordering_unpaid_order_claim'));
    }

    public function testTheClaimGoesWithItsOrder(): void
    {
        $order = $this->order();
        $this->claims->add($order, self::ADDRESS, self::guest('jan@example.com'), $this->now->modify('+35 minutes'));

        $this->connection->executeStatement('DELETE FROM ordering_order_line WHERE order_id = ?', [$order->toString()]);
        $this->connection->executeStatement('DELETE FROM ordering_order WHERE id = ?', [$order->toString()]);

        self::assertSame(0, OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM ordering_unpaid_order_claim'));
    }

    public function testBothCountsHaveAnIndexOnTheKeyAndTheExpiry(): void
    {
        $indexes = $this->connection->fetchAllKeyValue(
            "SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'ordering_unpaid_order_claim' AND indexname <> 'ordering_unpaid_order_claim_pkey' ORDER BY indexname",
        );

        self::assertSame([
            'ordering_unpaid_order_claim_address_idx' => 'CREATE INDEX ordering_unpaid_order_claim_address_idx ON public.ordering_unpaid_order_claim USING btree (address_key, expires_at)',
            'ordering_unpaid_order_claim_identity_idx' => 'CREATE INDEX ordering_unpaid_order_claim_identity_idx ON public.ordering_unpaid_order_claim USING btree (identity_key, expires_at)',
        ], $indexes);
    }

    private function order(): OrderId
    {
        return OrderId::fromString(OrderingFixture::order($this->connection));
    }

    private static function guest(string $email): UnpaidOrderIdentity
    {
        return UnpaidOrderIdentity::of(OrderOwner::guest(), EmailAddress::fromString($email));
    }
}
