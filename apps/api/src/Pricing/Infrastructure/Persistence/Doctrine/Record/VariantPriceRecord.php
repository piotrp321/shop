<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Persistence model of the VariantPrice aggregate. Only DoctrineVariantPriceRepository maps it to and from the domain.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pricing_variant_price')]
class VariantPriceRecord
{
    /** PostgreSQL's default name of the primary key constraint of the table. */
    public const string PRIMARY_KEY_CONSTRAINT = 'pricing_variant_price_pkey';

    #[ORM\Column(length: 3)]
    public string $currency;

    #[ORM\Column]
    public int $grossAmount;

    #[ORM\Column]
    public int $netAmount;

    #[ORM\Column]
    public int $vatAmount;

    #[ORM\Column]
    public int $vatRateBp;

    #[ORM\Column(length: 32)]
    public string $taxClass;

    /** Optimistic locking: the versioned UPDATE compares with the value set here before the flush. */
    #[ORM\Version]
    #[ORM\Column(type: Types::BIGINT)]
    public int $version = 1;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public readonly string $variantId,
    ) {
    }
}
