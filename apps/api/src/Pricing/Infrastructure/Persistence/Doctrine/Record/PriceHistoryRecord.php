<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One entry of the price history. Read-only for Doctrine: rows are inserted and never updated.
 * The index serves the newest-first history of a variant and the lowest price in a period (Omnibus).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'pricing_price_history')]
#[ORM\Index(name: 'pricing_price_history_variant_changed_at_idx', columns: ['variant_id', 'changed_at', 'id'])]
class PriceHistoryRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public readonly string $id,
        #[ORM\Column(type: 'guid')]
        public readonly string $variantId,
        #[ORM\Column(length: 3)]
        public readonly string $currency,
        #[ORM\Column]
        public readonly int $grossAmount,
        #[ORM\Column]
        public readonly int $netAmount,
        #[ORM\Column]
        public readonly int $vatAmount,
        #[ORM\Column]
        public readonly int $vatRateBp,
        #[ORM\Column(length: 32)]
        public readonly string $taxClass,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public readonly \DateTimeImmutable $changedAt,
        #[ORM\Column(length: 32)]
        public readonly string $source,
        #[ORM\Column(nullable: true)]
        public readonly ?string $actorId,
        #[ORM\Column(nullable: true)]
        public readonly ?string $actorName,
    ) {
    }
}
