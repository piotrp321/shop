<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of an order, in its position, with copies of the variant's names, unit price and promotion, its discount
 * and its values after it. The foreign key to its order has no ON DELETE CASCADE: orders are kept for ever. The
 * migrations add the CHECKs of the quantity (1 to 99), of the discount (not negative) and of the promotion (its four
 * columns together or none).
 * The names and the tax class are at least as long as the columns they are copied from (Catalog's product name
 * 200, a label of two values of 40 joined by a comma 82, Pricing's tax class 32).
 */
#[ORM\Entity]
#[ORM\Table(name: 'ordering_order_line')]
#[ORM\UniqueConstraint(name: 'ordering_order_line_position_key', columns: ['order_id', 'position'])]
#[ORM\UniqueConstraint(name: 'ordering_order_line_variant_key', columns: ['order_id', 'variant_id'])]
// DBAL requires an index with exactly the foreign key columns; the unique indexes do not fulfil it, so it is named
// here instead of being auto-generated as IDX_*.
#[ORM\Index(name: 'ordering_order_line_order_id_idx', columns: ['order_id'])]
class OrderLineRecord
{
    #[ORM\Column(type: 'guid')]
    public string $productId;

    #[ORM\Column(length: 255)]
    public string $productName;

    #[ORM\Column(length: 255)]
    public string $variantLabel;

    #[ORM\Column(type: Types::INTEGER)]
    public int $quantity;

    #[ORM\Column(type: Types::INTEGER)]
    public int $unitNet;

    #[ORM\Column(type: Types::INTEGER)]
    public int $unitVat;

    #[ORM\Column(type: Types::INTEGER)]
    public int $unitGross;

    #[ORM\Column(type: Types::INTEGER)]
    public int $vatRateBp;

    #[ORM\Column(length: 50)]
    public string $taxClass;

    #[ORM\Column(type: Types::BIGINT)]
    public int $lineNet;

    #[ORM\Column(type: Types::BIGINT)]
    public int $lineVat;

    #[ORM\Column(type: Types::BIGINT)]
    public int $lineGross;

    /** The share of the line in the discount of the code; 0 for a line of an order without one. */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    public int $discountGross = 0;

    #[ORM\Column(type: 'guid', nullable: true)]
    public ?string $promotionId = null;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $promotionName = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    public ?int $promotionPercentOff = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    public ?int $regularUnitGross = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public readonly string $id,
        #[ORM\ManyToOne(targetEntity: OrderRecord::class, inversedBy: 'lines')]
        #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false)]
        public readonly OrderRecord $order,
        #[ORM\Column(type: Types::INTEGER)]
        public readonly int $position,
        #[ORM\Column(type: 'guid')]
        public readonly string $variantId,
    ) {
    }
}
