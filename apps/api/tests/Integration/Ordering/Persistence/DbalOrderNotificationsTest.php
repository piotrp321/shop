<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Persistence;

use App\Ordering\Application\Port\NotificationClaim;
use App\Ordering\Application\Port\NotificationKind;
use App\Ordering\Domain\OrderId;
use App\Ordering\Infrastructure\Persistence\Doctrine\DbalOrderNotifications;
use App\Tests\Integration\Ordering\OrderingFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The sending lease of the order notifications on PostgreSQL, in the transaction DAMA rolls back after each test.
 * Every statement is atomic on its own, so the results are those concurrent handlers see one after another.
 */
final class DbalOrderNotificationsTest extends KernelTestCase
{
    private const string NOW = '2026-11-07 12:00:00+00:00';

    private Connection $connection;
    private DbalOrderNotifications $notifications;
    private OrderId $order;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->notifications = new DbalOrderNotifications($connection);
        $this->order = OrderId::fromString(OrderingFixture::order($connection));
    }

    public function testAFreshNotificationIsClaimedForTheLease(): void
    {
        self::assertEquals(NotificationClaim::claimed(), $this->notifications->claim($this->order, NotificationKind::Confirmation, $this->at()));

        self::assertSame([['kind' => 'confirmation', 'claimed_until' => '2026-11-07 12:05:00', 'sent_at' => null]], $this->rows());
    }

    public function testAHeldClaimIsInProgressUntilTheEndOfTheLease(): void
    {
        $this->notifications->claim($this->order, NotificationKind::Confirmation, $this->at());

        $claim = $this->notifications->claim($this->order, NotificationKind::Confirmation, $this->at('+4 minutes 59 seconds'));

        self::assertEquals(NotificationClaim::inProgress($this->at('+5 minutes')), $claim);
        self::assertFalse($claim->isClaimed());
        self::assertSame('2026-11-07 12:05:00', $this->rows()[0]['claimed_until'], 'the lease of the holder stays');
    }

    public function testAnExpiredLeaseIsClaimedAgain(): void
    {
        $this->notifications->claim($this->order, NotificationKind::Confirmation, $this->at());

        self::assertEquals(NotificationClaim::claimed(), $this->notifications->claim($this->order, NotificationKind::Confirmation, $this->at('+5 minutes')));
        self::assertSame('2026-11-07 12:10:00', $this->rows()[0]['claimed_until']);
    }

    public function testAReleasedClaimIsClaimedAgain(): void
    {
        $this->notifications->claim($this->order, NotificationKind::PaymentReceived, $this->at());

        $this->notifications->release($this->order, NotificationKind::PaymentReceived);

        self::assertSame([['kind' => 'payment_received', 'claimed_until' => null, 'sent_at' => null]], $this->rows());
        self::assertEquals(NotificationClaim::claimed(), $this->notifications->claim($this->order, NotificationKind::PaymentReceived, $this->at('+1 second')));
    }

    public function testAClaimAfterMarkSentIsAlreadySentAlsoAfterTheLease(): void
    {
        $this->notifications->claim($this->order, NotificationKind::Cancelled, $this->at());

        $this->notifications->markSent($this->order, NotificationKind::Cancelled, $this->at('+3 seconds'));

        self::assertSame([['kind' => 'cancelled', 'claimed_until' => null, 'sent_at' => '2026-11-07 12:00:03']], $this->rows());
        self::assertEquals(NotificationClaim::alreadySent(), $this->notifications->claim($this->order, NotificationKind::Cancelled, $this->at('+1 second')));
        self::assertEquals(NotificationClaim::alreadySent(), $this->notifications->claim($this->order, NotificationKind::Cancelled, $this->at('+1 day')));
    }

    public function testASentNotificationKeepsTheFirstMomentAndARelease(): void
    {
        $this->notifications->claim($this->order, NotificationKind::Cancelled, $this->at());
        $this->notifications->markSent($this->order, NotificationKind::Cancelled, $this->at('+3 seconds'));

        $this->notifications->markSent($this->order, NotificationKind::Cancelled, $this->at('+10 minutes'));
        $this->notifications->release($this->order, NotificationKind::Cancelled);

        self::assertSame([['kind' => 'cancelled', 'claimed_until' => null, 'sent_at' => '2026-11-07 12:00:03']], $this->rows());
    }

    public function testMarkSentWithoutAClaimStoresTheMoment(): void
    {
        $this->notifications->markSent($this->order, NotificationKind::Shipped, $this->at());

        self::assertSame([['kind' => 'shipped', 'claimed_until' => null, 'sent_at' => '2026-11-07 12:00:00']], $this->rows());
    }

    public function testTheKindsOfOneOrderAreIndependent(): void
    {
        $this->notifications->claim($this->order, NotificationKind::Confirmation, $this->at());
        $this->notifications->markSent($this->order, NotificationKind::PaymentReceived, $this->at());

        foreach ([NotificationKind::Cancelled, NotificationKind::Shipped, NotificationKind::ReturnReceived] as $kind) {
            self::assertEquals(NotificationClaim::claimed(), $this->notifications->claim($this->order, $kind, $this->at()), $kind->value);
        }
        $other = OrderId::fromString(OrderingFixture::order($this->connection));
        self::assertEquals(NotificationClaim::claimed(), $this->notifications->claim($other, NotificationKind::Confirmation, $this->at()));
    }

    public function testTheLeaseLeavesTheOrderAndItsVersionAlone(): void
    {
        $before = $this->connection->fetchAssociative('SELECT * FROM ordering_order WHERE id = ?', [$this->order->toString()]);

        $this->notifications->claim($this->order, NotificationKind::PaymentReceived, $this->at());
        $this->notifications->release($this->order, NotificationKind::PaymentReceived);
        $this->notifications->claim($this->order, NotificationKind::PaymentReceived, $this->at());
        $this->notifications->markSent($this->order, NotificationKind::PaymentReceived, $this->at());

        self::assertSame($before, $this->connection->fetchAssociative('SELECT * FROM ordering_order WHERE id = ?', [$this->order->toString()]));
    }

    private function at(string $modifier = '+0 seconds'): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW)->modify($modifier);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT kind, to_char(claimed_until AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS claimed_until, to_char(sent_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS sent_at FROM ordering_order_notification WHERE order_id = ? ORDER BY kind",
            [$this->order->toString()],
        );
    }
}
