<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine\Record;

use App\Ordering\Domain\UnpaidOrderIdentity;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The claim of a new order in the limit of unpaid orders (DoctrineUnpaidOrderClaims): the key of the address of the
 * client, the identity key and the expiry of the order. Outside the Order aggregate, so the limit never changes an
 * order or its version. The row lives until the order is paid or cancelled; the foreign key has ON DELETE CASCADE, as
 * the claim is worth nothing without its order. Both counts have an index on their key with the expiry.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ordering_unpaid_order_claim')]
#[ORM\Index(name: 'ordering_unpaid_order_claim_address_idx', columns: ['address_key', 'expires_at'])]
#[ORM\Index(name: 'ordering_unpaid_order_claim_identity_idx', columns: ['identity_key', 'expires_at'])]
class UnpaidOrderClaimRecord
{
    /** The longest key of ClientIp is an IPv6 /64 prefix (at most 24 characters); the column leaves room. */
    public const int ADDRESS_LENGTH = 64;

    public function __construct(
        #[ORM\Id]
        #[ORM\OneToOne(targetEntity: OrderRecord::class)]
        #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        public readonly OrderRecord $order,
        #[ORM\Column(length: self::ADDRESS_LENGTH)]
        public readonly string $addressKey,
        #[ORM\Column(length: UnpaidOrderIdentity::MAX_LENGTH)]
        public readonly string $identityKey,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public readonly \DateTimeImmutable $expiresAt,
    ) {
    }
}
