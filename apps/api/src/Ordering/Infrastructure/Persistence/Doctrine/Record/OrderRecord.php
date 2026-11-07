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
 * total_gross, paid_at and payment_id exactly for a paid order, cancelled_at and cancellation_reason exactly for
 * a cancelled one. No foreign key to the tables of other modules: the order keeps copies (payment_id too).
 */
#[ORM\Entity]
#[ORM\Table(name: 'ordering_order')]
#[ORM\UniqueConstraint(name: self::NUMBER_CONSTRAINT, columns: ['number'])]
#[ORM\UniqueConstraint(name: self::IDEMPOTENCY_KEY_CONSTRAINT, columns: ['idempotency_key'])]
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
    public ?\DateTimeImmutable $confirmationSentAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $paymentNotificationSentAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $cancellationNotificationSentAt = null;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    public int $version = 1;

    /** @var Collection<int, OrderLineRecord> by position */
    #[ORM\OneToMany(targetEntity: OrderLineRecord::class, mappedBy: 'order', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    public Collection $lines;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public readonly string $id,
    ) {
        $this->lines = new ArrayCollection();
    }
}
