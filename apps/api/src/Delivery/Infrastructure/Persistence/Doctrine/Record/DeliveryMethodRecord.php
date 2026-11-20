<?php

declare(strict_types=1);

namespace App\Delivery\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Persistence model of the DeliveryMethod aggregate. Only DoctrineDeliveryMethodRepository maps it to and from the
 * domain. The migration adds what the mapping cannot say: the unique index on lower(name) (NAME_CONSTRAINT; the
 * lower case of the UTF-8 locale of the database, so also of Polish letters) and the CHECK constraints of the ranges
 * of the price, the threshold and the position.
 */
#[ORM\Entity]
#[ORM\Table(name: 'delivery_method')]
#[ORM\Index(name: 'delivery_method_active_position_idx', columns: ['active', 'position'])]
class DeliveryMethodRecord
{
    public const string NAME_CONSTRAINT = 'delivery_method_name_key';

    #[ORM\Column(length: 100)]
    public string $name;

    /** Empty without a description. */
    #[ORM\Column(length: 200)]
    public string $description;

    #[ORM\Column(type: Types::BIGINT)]
    public int $priceGross;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    public ?int $freeFromGross = null;

    #[ORM\Column]
    public bool $active;

    #[ORM\Column(type: Types::SMALLINT)]
    public int $position;

    #[ORM\Column(length: 500, nullable: true)]
    public ?string $trackingUrlTemplate = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $updatedAt;

    /** Optimistic locking: the versioned UPDATE compares with the value set here before the flush. */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    public int $version = 1;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public readonly string $id,
    ) {
    }
}
