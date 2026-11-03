<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of a cart: a product (no foreign key to Catalog) once per cart. Deleted with its cart (ON DELETE CASCADE:
 * carts are short-lived). The surrogate id is time-ordered, so lines added in the same second keep their order.
 * The migration adds the CHECK of the quantity (1 to 99).
 */
#[ORM\Entity]
#[ORM\Table(name: 'cart_line')]
#[ORM\UniqueConstraint(name: 'cart_line_cart_product_key', columns: ['cart_id', 'product_id'])]
// The index Doctrine requires for the foreign key, named here; the unique index above would serve the lookups too.
#[ORM\Index(name: 'cart_line_cart_id_idx', columns: ['cart_id'])]
class CartLineRecord
{
    #[ORM\Column]
    public int $quantity;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $addedAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public readonly string $id,
        #[ORM\ManyToOne(targetEntity: CartRecord::class, inversedBy: 'lines')]
        #[ORM\JoinColumn(name: 'cart_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        public readonly CartRecord $cart,
        #[ORM\Column(type: 'guid')]
        public readonly string $productId,
    ) {
    }
}
