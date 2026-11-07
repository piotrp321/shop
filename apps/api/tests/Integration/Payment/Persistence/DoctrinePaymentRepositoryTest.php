<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment\Persistence;

use App\Payment\Domain\IdempotencyKey;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\PaymentId;
use App\Payment\Domain\PaymentInProgress;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\ProviderCode;
use App\Payment\Domain\ProviderEventId;
use App\Payment\Domain\ProviderPaymentId;
use App\Payment\Domain\RefundReason;
use App\Payment\Infrastructure\Persistence\Doctrine\DoctrinePaymentRepository;
use App\Tests\Unit\Payment\PaymentExamples;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DoctrinePaymentRepository and the schema of Payment on shop_test (rolled back after each test): the mapping, the
 * unique indexes that refuse a concurrent start, the CHECK constraints that refuse SQL breaking the rules, and the
 * migrated schema equal to the mapping.
 */
final class DoctrinePaymentRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private DoctrinePaymentRepository $payments;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->payments = new DoctrinePaymentRepository($entityManager);
    }

    public function testAPaymentIsStoredAndReadBackWithEveryField(): void
    {
        $payment = PaymentExamples::withSession(ProviderCode::DemoCard);
        $this->payments->add($payment);
        $payment->succeed(PaymentExamples::amount(), new \DateTimeImmutable('2026-11-05 12:19:30+00:00'), new \DateTimeImmutable('2026-11-05 12:20:00+00:00'));
        $payment->requireRefund(RefundReason::PaidAfterDeadline);
        $this->payments->save($payment);
        $this->entityManager->clear();

        $stored = $this->payments->findByIdempotencyKey(IdempotencyKey::fromString(PaymentExamples::KEY));

        self::assertNotNull($stored);
        self::assertSame(PaymentExamples::PAYMENT, $stored->id()->toString());
        self::assertSame(PaymentExamples::ORDER, $stored->orderId()->toString());
        self::assertSame(ProviderCode::DemoCard, $stored->provider());
        self::assertTrue($stored->amount()->equals(PaymentExamples::amount()));
        self::assertSame(PaymentStatus::Succeeded, $stored->status());
        self::assertSame(PaymentExamples::SESSION, $stored->providerPaymentId()?->toString());
        self::assertSame(PaymentExamples::REDIRECT, $stored->redirectUrl());
        self::assertTrue($stored->isReplayOf($payment->requestFingerprint()));
        self::assertEquals(new \DateTimeImmutable(PaymentExamples::CREATED), $stored->createdAt());
        self::assertEquals(new \DateTimeImmutable('2026-11-05 12:20:00+00:00'), $stored->completedAt());
        self::assertEquals(new \DateTimeImmutable('2026-11-05 12:19:30+00:00'), $stored->executedAt());
        self::assertSame(RefundReason::PaidAfterDeadline, $stored->refundRequiredReason());
        self::assertNull($this->payments->findByIdempotencyKey(IdempotencyKey::fromString('another')));
    }

    public function testThePendingPaymentOfAnOrderIsReadUnderALockAndAnEndedOneIsNot(): void
    {
        $payment = PaymentExamples::withSession();
        $this->payments->add($payment);
        $order = OrderId::fromString(PaymentExamples::ORDER);

        $this->connection->beginTransaction();
        try {
            self::assertSame(PaymentExamples::PAYMENT, $this->payments->lockPendingOfOrder($order)?->id()->toString());
            self::assertTrue($this->holdsARowLock(), 'the row is locked by this transaction');
        } finally {
            $this->connection->rollBack();
        }

        $payment->cancel(new \DateTimeImmutable('2026-11-05 12:10:00+00:00'));
        $this->payments->save($payment);
        $this->connection->beginTransaction();
        try {
            self::assertNull($this->payments->lockPendingOfOrder($order));
        } finally {
            $this->connection->rollBack();
        }
    }

    public function testTheLockReadsTheRowAnewWhenItsRecordIsInTheIdentityMap(): void
    {
        $this->payments->add(PaymentExamples::withSession());
        // Another transaction ended the payment; the record in the identity map still says pending.
        $this->connection->executeStatement("UPDATE payment_payment SET status = 'failed', completed_at = now() WHERE id = ?", [PaymentExamples::PAYMENT]);

        $this->connection->beginTransaction();
        try {
            self::assertNull($this->payments->lockPendingOfOrder(OrderId::fromString(PaymentExamples::ORDER)));
        } finally {
            $this->connection->rollBack();
        }
    }

    public function testTheLockRefreshesARecordInTheIdentityMap(): void
    {
        $this->payments->add(PaymentExamples::withSession());
        $this->connection->executeStatement("UPDATE payment_payment SET redirect_url = 'http://provider.test/changed' WHERE id = ?", [PaymentExamples::PAYMENT]);
        $this->connection->beginTransaction();
        try {
            $locked = $this->payments->lockPendingOfOrder(OrderId::fromString(PaymentExamples::ORDER));
            self::assertSame('http://provider.test/changed', $locked?->redirectUrl(), 'refreshed under the lock');
        } finally {
            $this->connection->rollBack();
        }
    }

    public function testThePaymentOfASessionOfItsProviderIsReadUnderALock(): void
    {
        $this->payments->add(PaymentExamples::withSession());
        $this->entityManager->clear();

        $this->connection->beginTransaction();
        try {
            $locked = $this->payments->lockByProviderPayment(ProviderCode::DemoTransfer, ProviderPaymentId::fromString(PaymentExamples::SESSION));
            self::assertSame(PaymentExamples::PAYMENT, $locked?->id()->toString());
            self::assertTrue($this->holdsARowLock(), 'the row is locked by this transaction');
            self::assertNull($this->payments->lockByProviderPayment(ProviderCode::DemoCard, ProviderPaymentId::fromString(PaymentExamples::SESSION)), 'the same id at another provider');
            self::assertNull($this->payments->lockByProviderPayment(ProviderCode::DemoTransfer, ProviderPaymentId::fromString('tx_unknown')));
        } finally {
            $this->connection->rollBack();
        }
    }

    public function testTheLockOfASessionReadsTheRowAnewWhenItsRecordIsInTheIdentityMap(): void
    {
        $this->payments->add(PaymentExamples::withSession());
        // Another transaction (a webhook before) changed the payment; the record in the identity map is older.
        $this->connection->executeStatement("UPDATE payment_payment SET status = 'succeeded', executed_at = now(), completed_at = now() WHERE id = ?", [PaymentExamples::PAYMENT]);

        $this->connection->beginTransaction();
        try {
            $locked = $this->payments->lockByProviderPayment(ProviderCode::DemoTransfer, ProviderPaymentId::fromString(PaymentExamples::SESSION));
            self::assertSame(PaymentStatus::Succeeded, $locked?->status(), 'refreshed under the lock');
        } finally {
            $this->connection->rollBack();
        }
    }

    public function testAWebhookEventIsRecordedOnceAndItsRepeatIsReported(): void
    {
        $payment = PaymentId::fromString(PaymentExamples::PAYMENT);
        $event = ProviderEventId::fromString('evt_1');
        $received = new \DateTimeImmutable('2026-11-05 12:21:00+00:00');

        self::assertTrue($this->payments->recordWebhookEvent(ProviderCode::DemoTransfer, $event, $payment, $received));
        self::assertFalse($this->payments->recordWebhookEvent(ProviderCode::DemoTransfer, $event, $payment, $received->modify('+1 minute')), 'a repeat');
        self::assertTrue($this->payments->recordWebhookEvent(ProviderCode::DemoCard, $event, $payment, $received), 'the same id at another provider');

        self::assertSame(
            [['provider' => 'demo-transfer', 'event_id' => 'evt_1', 'payment_id' => PaymentExamples::PAYMENT, 'received_at' => '2026-11-05 12:21:00+00']],
            $this->connection->fetchAllAssociative("SELECT provider, event_id, payment_id, received_at AT TIME ZONE 'UTC' || '+00' AS received_at FROM payment_webhook_event WHERE provider = 'demo-transfer'"),
        );
    }

    public function testARepeatedWebhookEventLeavesTheEntityManagerOpen(): void
    {
        $payment = PaymentId::fromString(PaymentExamples::PAYMENT);
        $this->payments->recordWebhookEvent(ProviderCode::DemoCard, ProviderEventId::fromString('evt_2'), $payment, new \DateTimeImmutable());

        $this->payments->recordWebhookEvent(ProviderCode::DemoCard, ProviderEventId::fromString('evt_2'), $payment, new \DateTimeImmutable());

        self::assertTrue($this->entityManager->isOpen(), 'no unique violation that would close it');
        $this->payments->add(PaymentExamples::withSession());
    }

    public function testTheLatestPaymentOfAnOrderIsTheNewest(): void
    {
        $older = PaymentExamples::withSession(id: '0192f0c4-7c1a-7c4e-9a6e-0000000000a1', key: 'first');
        $older->fail(new \DateTimeImmutable('2026-11-05 12:06:00+00:00'));
        $this->payments->add($older);
        $newer = PaymentExamples::withSession(ProviderCode::DemoCard, '0192f0c4-7c1a-7c4e-9a6e-0000000000a2', 'second', 'cs_2');
        $this->payments->add($newer);
        $this->entityManager->clear();

        self::assertSame('0192f0c4-7c1a-7c4e-9a6e-0000000000a2', $this->payments->latestOfOrder(OrderId::fromString(PaymentExamples::ORDER))?->id()->toString(), 'the same second: the greater UUID v7');
        self::assertNull($this->payments->latestOfOrder(OrderId::fromString('0192f0c4-7c1a-7c4e-9a6e-0000000000ff')));
    }

    public function testASecondPaymentWithTheSameKeyIsInProgress(): void
    {
        $this->payments->add(PaymentExamples::withSession(key: 'same'));
        $other = PaymentExamples::started(ProviderCode::DemoCard, '0192f0c4-7c1a-7c4e-9a6e-0000000000a2', 'same');
        $this->connection->executeStatement("UPDATE payment_payment SET status = 'failed', completed_at = now()");

        $this->expectExceptionObject(PaymentInProgress::concurrentStart());

        $this->payments->add($other);
    }

    public function testASecondPendingPaymentOfAnOrderIsInProgress(): void
    {
        $this->payments->add(PaymentExamples::withSession(key: 'first'));

        $this->expectExceptionObject(PaymentInProgress::concurrentStart());

        $this->payments->add(PaymentExamples::started(ProviderCode::DemoCard, '0192f0c4-7c1a-7c4e-9a6e-0000000000a2', 'second'));
    }

    public function testAnEndedPaymentLeavesRoomForAPendingOne(): void
    {
        $first = PaymentExamples::withSession(key: 'first');
        $first->cancel(new \DateTimeImmutable('2026-11-05 12:06:00+00:00'));
        $this->payments->add($first);

        $this->payments->add(PaymentExamples::withSession(ProviderCode::DemoCard, '0192f0c4-7c1a-7c4e-9a6e-0000000000a2', 'second', 'cs_2'));

        self::assertSame(2, $this->connection->fetchOne('SELECT COUNT(*) FROM payment_payment'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenRows(): iterable
    {
        yield 'zero amount' => ['UPDATE payment_payment SET amount = 0'];
        yield 'unknown provider' => ["UPDATE payment_payment SET provider = 'paypal'"];
        yield 'unknown status' => ["UPDATE payment_payment SET status = 'paid'"];
        yield 'succeeded without the time of execution' => ["UPDATE payment_payment SET status = 'succeeded'"];
        yield 'a time of execution without success' => ['UPDATE payment_payment SET executed_at = now()'];
        yield 'a refund of a pending payment' => ["UPDATE payment_payment SET refund_required_reason = 'duplicate'"];
        yield 'an unknown refund reason' => ["UPDATE payment_payment SET status = 'succeeded', executed_at = now(), refund_required_reason = 'whim'"];
        yield 'the session of another payment' => ["INSERT INTO payment_payment (id, order_id, provider, amount, currency, status, provider_payment_id, idempotency_key, request_fingerprint, created_at) VALUES ('0192f0c4-7c1a-7c4e-9a6e-0000000000a9', '0192f0c4-7c1a-7c4e-9a6e-0000000000d9', 'demo-transfer', 1, 'PLN', 'failed', 'tx_0001', 'other', repeat('a', 64), now())"];
    }

    #[DataProvider('brokenRows')]
    public function testTheSchemaRefusesARowBreakingTheRules(string $sql): void
    {
        $this->payments->add(PaymentExamples::withSession());

        $this->expectException(DriverException::class);

        $this->connection->executeStatement($sql);
    }

    public function testASucceededPaymentMayNeedARefund(): void
    {
        $this->payments->add(PaymentExamples::withSession());

        self::assertSame(1, $this->connection->executeStatement("UPDATE payment_payment SET status = 'succeeded', executed_at = now(), refund_required_reason = 'order_cancelled'"));
    }

    public function testAWebhookEventIsKeptOncePerProvider(): void
    {
        $insert = "INSERT INTO payment_webhook_event (provider, event_id, payment_id, received_at) VALUES (?, 'evt_1', ?, now())";
        $this->connection->executeStatement($insert, ['demo-transfer', PaymentExamples::PAYMENT]);
        $this->connection->executeStatement($insert, ['demo-card', PaymentExamples::PAYMENT]);

        $this->expectException(DriverException::class);

        $this->connection->executeStatement($insert, ['demo-transfer', PaymentExamples::PAYMENT]);
    }

    public function testMigratedSchemaMatchesTheMapping(): void
    {
        $updates = array_values(array_filter(
            new SchemaTool($this->entityManager)->getUpdateSchemaSql($this->entityManager->getMetadataFactory()->getAllMetadata()),
            static fn (string $sql): bool => str_contains($sql, 'payment_payment') || str_contains($sql, 'payment_webhook_event'),
        ));

        self::assertSame([], $updates);
    }

    /**
     * `SELECT … FOR UPDATE` takes RowShareLock on the table (and the row lock on the row itself).
     */
    private function holdsARowLock(): bool
    {
        return true === $this->connection->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM pg_locks l JOIN pg_class c ON c.oid = l.relation WHERE c.relname = 'payment_payment' AND l.pid = pg_backend_pid() AND l.mode = 'RowShareLock')",
        );
    }
}
