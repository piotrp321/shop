<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Persistence model of the Order aggregate; only DoctrineOrderRepository maps it to and from the domain. The number
 * and the Idempotency-Key are unique. The migration adds the CHECK constraints the mapping cannot express: a customer
 * id exactly for an order of a customer, an access token exactly for an order of a guest, total_net + total_vat =
 * total_gross, paid_at together with payment_id exactly for an order paid for (paid, shipped, delivered, returned,
 * cancelled by staff), cancelled_at and cancellation_reason exactly for a cancelled one, shipped_at exactly from
 * shipped on, delivered_at only for delivered and returned, returned_at exactly for returned, the refund (amount and
 * due date) exactly for returned and cancelled by staff, its completion (moment, staff id and name together, the note
 * only with them) only with a refund, the copy of the discount code (code, kind, value, amount) together or not at all.
 * No foreign key to the tables of other modules: the order keeps copies
 * (payment_id too).
 */
#[ORM\Entity]
#[ORM\Table(name: 'ordering_order')]
#[ORM\UniqueConstraint(name: self::NUMBER_CONSTRAINT, columns: ['number'])]
#[ORM\UniqueConstraint(name: self::IDEMPOTENCY_KEY_CONSTRAINT, columns: ['idempotency_key'])]
// The list of the panel: the migration makes the first two descending (placed_at DESC, id DESC), which the mapping
// cannot express, and adds an index on lower(email) the mapping does not know (an expression, so the schema tools
// ignore it). The condition of the pending refunds is written as PostgreSQL returns it, so the mapped schema equals
// the migrated one.
#[ORM\Index(name: 'ordering_order_placed_at_id_idx', columns: ['placed_at', 'id'])]
#[ORM\Index(name: 'ordering_order_status_placed_at_id_idx', columns: ['status', 'placed_at', 'id'])]
// The history of a customer: (customer_id, placed_at DESC, id DESC) of the orders of customers only, written the same way.
#[ORM\Index(name: 'ordering_order_customer_placed_at_id_idx', columns: ['customer_id', 'placed_at', 'id'], options: ['where' => "((owner_type)::text = 'customer'::text)"])]
#[ORM\Index(name: 'ordering_order_pending_refund_idx', columns: ['refund_due_at', 'id'], options: ['where' => '((refund_amount IS NOT NULL) AND (refund_completed_at IS NULL))'])]
class OrderRecord
{
    public const string NUMBER_CONSTRAINT = 'ordering_order_number_key';
    public const string IDEMPOTENCY_KEY_CONSTRAINT = 'ordering_order_idempotency_key_key';

    #[ORM\Column(length: 20)]
    public string $number;

    #[ORM\Column(length: 20)]
    public string $status;

    #[ORM\Column(length: 16)]
    public string $ownerType;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $customerId = null;

    #[ORM\Column(length: 43, nullable: true)]
    public ?string $accessToken = null;

    #[ORM\Column(length: 254)]
    public string $email;

    #[ORM\Column(length: 100)]
    public string $fullName;

    #[ORM\Column(length: 12)]
    public string $phone;

    #[ORM\Column(length: 100)]
    public string $street;

    #[ORM\Column(length: 6, options: ['fixed' => true])]
    public string $postalCode;

    #[ORM\Column(length: 60)]
    public string $city;

    #[ORM\Column(length: 2, options: ['fixed' => true])]
    public string $country;

    #[ORM\Column(length: 3, options: ['fixed' => true])]
    public string $currency;

    #[ORM\Column(type: Types::BIGINT)]
    public int $totalNet;

    #[ORM\Column(type: Types::BIGINT)]
    public int $totalVat;

    #[ORM\Column(type: Types::BIGINT)]
    public int $totalGross;

    /** The copy of the discount code: the migration requires the four columns together or none. */
    #[ORM\Column(length: 32, nullable: true)]
    public ?string $discountCode = null;

    #[ORM\Column(length: 8, nullable: true)]
    public ?string $discountKind = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    public ?int $discountValue = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    public ?int $discountGross = null;

    #[ORM\Column(length: 100)]
    public string $idempotencyKey;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    public string $requestFingerprint;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $placedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $paymentDeadline;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(type: 'guid', nullable: true)]
    public ?string $paymentId = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(length: 30, nullable: true)]
    public ?string $cancellationReason = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $shippedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $returnedAt = null;

    /** In grosze, in the currency of the order. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    public ?int $refundAmount = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $refundDueAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $refundCompletedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $refundCompletedByStaffId = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $refundCompletedByUsername = null;

    #[ORM\Column(length: 500, nullable: true)]
    public ?string $refundNote = null;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    public int $version = 1;

    /** @var Collection<int, OrderLineRecord> by position */
    #[ORM\OneToMany(targetEntity: OrderLineRecord::class, mappedBy: 'order', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    public Collection $lines;

    /** @var Collection<int, OrderStatusChangeRecord> by position */
    #[ORM\OneToMany(targetEntity: OrderStatusChangeRecord::class, mappedBy: 'order', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    public Collection $statusChanges;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public readonly string $id,
    ) {
        $this->lines = new ArrayCollection();
        $this->statusChanges = new ArrayCollection();
    }
}
