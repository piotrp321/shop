<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\ORM\Mapping as ORM;

/**
 * A variant of a promotion. No foreign key to Catalog (a module boundary): a variant deleted from the catalog stays in
 * the promotions it was in. The index by variant serves the promotions of a variant (overlaps, the price lock).
 */
#[ORM\Entity]
#[ORM\Table(name: 'pricing_promotion_variant')]
#[ORM\Index(name: 'pricing_promotion_variant_variant_id_idx', columns: ['variant_id'])]
// DBAL requires an index with exactly the foreign key column; the primary key (promotion_id, variant_id) does not
// fulfil it, so it is named here instead of being auto-generated as IDX_*.
#[ORM\Index(name: 'pricing_promotion_variant_promotion_id_idx', columns: ['promotion_id'])]
class PromotionVariantRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: PromotionRecord::class, inversedBy: 'variants')]
        #[ORM\JoinColumn(name: 'promotion_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        public readonly PromotionRecord $promotion,
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public readonly string $variantId,
    ) {
    }
}
