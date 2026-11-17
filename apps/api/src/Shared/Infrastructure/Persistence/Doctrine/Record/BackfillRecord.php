<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Schema definition of a completed step of app:backfill. BackfillLog reads and writes the row with plain statements
 * (DBAL); the record is here for doctrine:schema and the migrations.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_backfill')]
class BackfillRecord
{
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $completedAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 200)]
        public readonly string $name,
    ) {
    }
}
