<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Persistence\Doctrine;

use App\Payment\Domain\Amount;
use App\Payment\Domain\Currency;
use App\Payment\Domain\IdempotencyKey;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentId;
use App\Payment\Domain\PaymentInProgress;
use App\Payment\Domain\PaymentRepository;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\ProviderCode;
use App\Payment\Domain\ProviderEventId;
use App\Payment\Domain\ProviderPaymentId;
use App\Payment\Domain\RefundReason;
use App\Payment\Domain\RequestFingerprint;
use App\Payment\Infrastructure\Persistence\Doctrine\Record\PaymentRecord;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * Maps the Payment aggregate to and from its record, in the transaction of command.bus; every write flushes at once.
 *
 * A start that lost a race with a concurrent one (the same Idempotency-Key, or a second pending payment of the same
 * order) is refused by a unique index; the violation becomes PaymentInProgress. It closes the EntityManager, like
 * every failed flush, so the transaction ends with it.
 *
 * The pending payment of an order is read under the lock of its row (`SELECT … FOR UPDATE`), and read anew even when
 * its record is in the identity map (a hint to refresh): PostgreSQL checks the condition again once the lock is
 * granted, so a payment a concurrent transaction ended meanwhile is not returned. The payment of a session of its
 * provider (the webhook) is locked the same way.
 *
 * A handled webhook event is written by DBAL with `INSERT … ON CONFLICT DO NOTHING` (its record only defines the
 * schema): a repeat is reported without a unique violation that would close the EntityManager, and a row of a
 * transaction that was rolled back never lingers in the identity map to look like a repeat.
 */
final readonly class DoctrinePaymentRepository implements PaymentRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(Payment $payment): void
    {
        $record = new PaymentRecord($payment->id()->toString());
        $record->orderId = $payment->orderId()->toString();
        $record->provider = $payment->provider()->value;
        $record->amount = $payment->amount()->minorUnits();
        $record->currency = $payment->amount()->currency()->value;
        $record->idempotencyKey = $payment->idempotencyKey()->toString();
        $record->requestFingerprint = $payment->requestFingerprint()->toString();
        $record->createdAt = $payment->createdAt();
        self::writeChanges($payment, $record);
        $this->entityManager->persist($record);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $violation) {
            foreach ([PaymentRecord::IDEMPOTENCY_KEY_CONSTRAINT, PaymentRecord::ONE_PENDING_CONSTRAINT] as $constraint) {
                if (str_contains($violation->getMessage(), $constraint)) {
                    throw PaymentInProgress::concurrentStart();
                }
            }

            throw $violation;
        }
    }

    public function save(Payment $payment): void
    {
        $record = $this->entityManager->find(PaymentRecord::class, $payment->id()->toString())
            ?? throw new \LogicException('A payment is saved after it was added.');
        self::writeChanges($payment, $record);
        $this->entityManager->flush();
    }

    public function findByIdempotencyKey(IdempotencyKey $key): ?Payment
    {
        $record = $this->entityManager->getRepository(PaymentRecord::class)->findOneBy(['idempotencyKey' => $key->toString()]);

        return null === $record ? null : self::toDomain($record);
    }

    public function lockPendingOfOrder(OrderId $orderId): ?Payment
    {
        $record = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(PaymentRecord::class, 'p')
            ->where('p.orderId = :orderId')
            ->andWhere('p.status = :pending')
            ->setParameter('orderId', $orderId->toString())
            ->setParameter('pending', PaymentStatus::Pending->value)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
        \assert(null === $record || $record instanceof PaymentRecord);

        return null === $record ? null : self::toDomain($record);
    }

    public function lockByProviderPayment(ProviderCode $provider, ProviderPaymentId $providerPaymentId): ?Payment
    {
        $record = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(PaymentRecord::class, 'p')
            ->where('p.provider = :provider')
            ->andWhere('p.providerPaymentId = :providerPaymentId')
            ->setParameter('provider', $provider->value)
            ->setParameter('providerPaymentId', $providerPaymentId->toString())
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
        \assert(null === $record || $record instanceof PaymentRecord);

        return null === $record ? null : self::toDomain($record);
    }

    public function recordWebhookEvent(ProviderCode $provider, ProviderEventId $eventId, PaymentId $paymentId, \DateTimeImmutable $receivedAt): bool
    {
        return 1 === $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO payment_webhook_event (provider, event_id, payment_id, received_at) VALUES (?, ?, ?, ?) ON CONFLICT DO NOTHING',
            [$provider->value, $eventId->toString(), $paymentId->toString(), $receivedAt],
            [Types::STRING, Types::STRING, Types::GUID, Types::DATETIMETZ_IMMUTABLE],
        );
    }

    public function latestOfOrder(OrderId $orderId): ?Payment
    {
        // Ids are UUID v7, so of two payments created in the same second the later one has the greater id.
        $record = $this->entityManager->getRepository(PaymentRecord::class)->findOneBy(['orderId' => $orderId->toString()], ['createdAt' => 'DESC', 'id' => 'DESC']);

        return null === $record ? null : self::toDomain($record);
    }

    private static function writeChanges(Payment $payment, PaymentRecord $record): void
    {
        $record->status = $payment->status()->value;
        $record->providerPaymentId = $payment->providerPaymentId()?->toString();
        $record->redirectUrl = $payment->redirectUrl();
        $record->completedAt = $payment->completedAt();
        $record->executedAt = $payment->executedAt();
        $record->refundRequiredReason = $payment->refundRequiredReason()?->value;
    }

    private static function toDomain(PaymentRecord $record): Payment
    {
        return Payment::reconstitute(
            id: PaymentId::fromString($record->id),
            orderId: OrderId::fromString($record->orderId),
            provider: ProviderCode::from($record->provider),
            amount: Amount::of($record->amount, Currency::from($record->currency)),
            status: PaymentStatus::from($record->status),
            providerPaymentId: null === $record->providerPaymentId ? null : ProviderPaymentId::fromString($record->providerPaymentId),
            redirectUrl: $record->redirectUrl,
            idempotencyKey: IdempotencyKey::fromString($record->idempotencyKey),
            requestFingerprint: RequestFingerprint::fromString($record->requestFingerprint),
            createdAt: $record->createdAt,
            completedAt: $record->completedAt,
            executedAt: $record->executedAt,
            refundRequiredReason: null === $record->refundRequiredReason ? null : RefundReason::from($record->refundRequiredReason),
        );
    }
}
