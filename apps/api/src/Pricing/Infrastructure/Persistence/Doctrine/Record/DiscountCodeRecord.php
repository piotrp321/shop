<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Persistence model of the DiscountCode aggregate. Only DoctrineDiscountCodeRepository maps it to and from the
 * domain. The text is stored normalised (upper case), so the unique index makes it unique without regard to case.
 * The migration adds the CHECK constraints (the value of its kind only, the percent 1 to 90, the minimum of an
 * amount code above its amount, start before end, an actor with both its id and name) and makes the index of the list
 * descending by creation, as the mapping cannot say.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pricing_discount_code')]
#[ORM\UniqueConstraint(name: self::CODE_CONSTRAINT, columns: ['code'])]
#[ORM\Index(name: 'pricing_discount_code_created_at_id_idx', columns: ['created_at', 'id'])]
class DiscountCodeRecord
{
    public const string CODE_CONSTRAINT = 'pricing_discount_code_code_key';

    #[ORM\Column(length: 32)]
    public string $code;

    /** `percent` or `amount`. */
    #[ORM\Column(length: 8)]
    public string $kind;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    public ?int $percentOff = null;

    #[ORM\Column(nullable: true)]
    public ?int $amountGross = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    public ?int $minimumOrderGross = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $startsAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(nullable: true)]
    public ?int $usageLimit = null;

    #[ORM\Column]
    public bool $enabled;

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

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public readonly string $id,
    ) {
    }
}
