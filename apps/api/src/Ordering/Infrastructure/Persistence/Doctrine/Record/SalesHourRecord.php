<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The sales of one hour (UTC, its start) in one of the 16 shards of the order ids: the number and the gross total of
 * the orders paid in it. Only defines the schema: DbalSalesHours reads and writes the rows by DBAL. The shards let the
 * payments of one hour lock different rows (a lock lasts until the end of the webhook). The migration adds the CHECK
 * constraints of the shard, of the start of an hour and of the non-negative numbers.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ordering_sales_hour')]
class SalesHourRecord
{
    #[ORM\Column(type: Types::INTEGER)]
    public int $orderCount = 0;

    /** In grosze. */
    #[ORM\Column(type: Types::BIGINT)]
    public int $gross = 0;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public readonly \DateTimeImmutable $hourStart,
        #[ORM\Id]
        #[ORM\Column(type: Types::SMALLINT)]
        public readonly int $shard,
    ) {
    }
}
