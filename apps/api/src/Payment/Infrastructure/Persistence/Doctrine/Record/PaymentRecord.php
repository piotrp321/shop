<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Persistence model of the Payment aggregate; only DoctrinePaymentRepository maps it to and from the domain. The
 * Idempotency-Key and the session at the provider are unique, and an order has at most one pending payment (a
 * partial unique index). The migration adds the CHECK
 * constraints: a positive amount, the known providers and statuses, `executed_at` exactly for a succeeded payment
 * and a refund reason only for one. No version and no foreign key to the tables of Ordering.
 */
#[ORM\Entity]
#[ORM\Table(name: 'payment_payment')]
#[ORM\UniqueConstraint(name: self::IDEMPOTENCY_KEY_CONSTRAINT, columns: ['idempotency_key'])]
#[ORM\UniqueConstraint(name: self::PROVIDER_PAYMENT_CONSTRAINT, columns: ['provider', 'provider_payment_id'])]
#[ORM\UniqueConstraint(name: self::ONE_PENDING_CONSTRAINT, columns: ['order_id'], options: ['where' => "((status)::text = 'pending'::text)"])]
#[ORM\Index(name: 'payment_payment_order_created_idx', columns: ['order_id', 'created_at'])]
class PaymentRecord
{
    public const string IDEMPOTENCY_KEY_CONSTRAINT = 'payment_payment_idempotency_key_key';
    public const string PROVIDER_PAYMENT_CONSTRAINT = 'payment_payment_provider_payment_key';
    /**
     * The partial unique index (order_id) WHERE status = 'pending'; the condition is written as PostgreSQL gives it
     * back, so the schema of the mapping equals the migrated one.
     */
    public const string ONE_PENDING_CONSTRAINT = 'payment_payment_one_pending_per_order';

    #[ORM\Column(type: 'guid')]
    public string $orderId;

    #[ORM\Column(length: 20)]
    public string $provider;

    #[ORM\Column(type: Types::BIGINT)]
    public int $amount;

    #[ORM\Column(length: 3, options: ['fixed' => true])]
    public string $currency;

    #[ORM\Column(length: 20)]
    public string $status;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $providerPaymentId = null;

    #[ORM\Column(length: 500, nullable: true)]
    public ?string $redirectUrl = null;

    #[ORM\Column(length: 100)]
    public string $idempotencyKey;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    public string $requestFingerprint;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $executedAt = null;

    #[ORM\Column(length: 30, nullable: true)]
    public ?string $refundRequiredReason = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public string $id,
    ) {
    }
}
