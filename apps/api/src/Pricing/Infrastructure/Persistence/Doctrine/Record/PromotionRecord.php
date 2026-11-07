<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Persistence model of the Promotion aggregate. Only DoctrinePromotionRepository maps it to and from the domain. The
 * migration adds the CHECK constraints (start before end, percent 1 to 90, an actor with both its id and name) and
 * makes the index of the list descending by start, as the mapping cannot say.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pricing_promotion')]
#[ORM\Index(name: 'pricing_promotion_starts_at_id_idx', columns: ['starts_at', 'id'])]
class PromotionRecord
{
    #[ORM\Column(length: 100)]
    public string $name;

    #[ORM\Column(type: Types::SMALLINT)]
    public int $percentOff;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $startsAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $endsAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    public ?string $createdById = null;

    #[ORM\Column(nullable: true)]
    public ?string $createdByName = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    public ?string $updatedById = null;

    #[ORM\Column(nullable: true)]
    public ?string $updatedByName = null;

    /** Optimistic locking: the versioned UPDATE compares with the value set here before the flush. */
    #[ORM\Version]
    #[ORM\Column(type: Types::BIGINT)]
    public int $version = 1;

    /** @var Collection<int, PromotionVariantRecord> */
    #[ORM\OneToMany(targetEntity: PromotionVariantRecord::class, mappedBy: 'promotion', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['variantId' => 'ASC'])]
    public Collection $variants;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public readonly string $id,
    ) {
        $this->variants = new ArrayCollection();
    }
}
