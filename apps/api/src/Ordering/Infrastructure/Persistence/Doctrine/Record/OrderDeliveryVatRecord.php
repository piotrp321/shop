<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The share of the cost of the delivery of an order at one VAT rate of its goods (docs/features/delivery/spec.md, W11),
 * written once with the order, never changed. The migration adds the CHECK of the amounts (net + VAT = gross, gross
 * above 0, net and VAT not negative). The foreign key to the order has no ON DELETE CASCADE, like the lines.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ordering_order_delivery_vat')]
// DBAL requires an index with exactly the foreign key column; the primary key (order_id, vat_rate_bp) does not fulfil
// it, so it is named here instead of being auto-generated as IDX_*.
#[ORM\Index(name: 'ordering_order_delivery_vat_order_id_idx', columns: ['order_id'])]
class OrderDeliveryVatRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: OrderRecord::class, inversedBy: 'deliveryShares')]
        #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false)]
        public readonly OrderRecord $order,
        #[ORM\Id]
        #[ORM\Column(type: Types::INTEGER)]
        public readonly int $vatRateBp,
        #[ORM\Column(type: Types::BIGINT)]
        public readonly int $gross,
        #[ORM\Column(type: Types::BIGINT)]
        public readonly int $net,
        #[ORM\Column(type: Types::BIGINT)]
        public readonly int $vat,
    ) {
    }
}
