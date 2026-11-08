<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A usage of a discount code by an order. The order is an id of Ordering without a foreign key (a module boundary);
 * an order has at most one usage. The partial index of the active usages serves their count (the condition is written
 * as PostgreSQL returns it, so the schema of the mapping equals the migrated one).
 */
#[ORM\Entity]
#[ORM\Table(name: 'pricing_discount_code_usage')]
#[ORM\UniqueConstraint(name: 'pricing_discount_code_usage_order_id_key', columns: ['order_id'])]
#[ORM\Index(name: 'pricing_discount_code_usage_active_idx', columns: ['code_id'], options: ['where' => '(released_at IS NULL)'])]
// DBAL requires an index with exactly the foreign key column; the partial index does not fulfil it, so it is named
// here instead of being auto-generated as IDX_*.
#[ORM\Index(name: 'pricing_discount_code_usage_code_id_idx', columns: ['code_id'])]
class DiscountCodeUsageRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public readonly string $id,
        #[ORM\ManyToOne(targetEntity: DiscountCodeRecord::class)]
        #[ORM\JoinColumn(name: 'code_id', referencedColumnName: 'id', nullable: false)]
        public readonly DiscountCodeRecord $code,
        #[ORM\Column(type: 'guid')]
        public readonly string $orderId,
        // Not readonly: a refresh sets it again with another instance of the same moment, which Doctrine refuses for
        // a readonly property.
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public \DateTimeImmutable $usedAt,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        public ?\DateTimeImmutable $releasedAt = null,
    ) {
    }
}
