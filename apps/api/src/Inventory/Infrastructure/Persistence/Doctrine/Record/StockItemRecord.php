<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Schema definition of the row of the StockItem aggregate. DoctrineStockItemRepository reads and writes the row with
 * plain statements (DBAL), not through this record; it is here for doctrine:schema and the migrations.
 *
 * The migration adds the CHECK constraint (0 ≤ reserved ≤ physical ≤ 1 000 000), the last line of defence of the
 * invariant; the mapping cannot express it. The version is not an ORM version: the operations on a stock are
 * serialised by its row lock (SELECT … FOR UPDATE), the repository writes the version it checked.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inventory_stock_item')]
class StockItemRecord
{
    public const string QUANTITIES_CHECK = 'inventory_stock_item_quantities_check';

    #[ORM\Column]
    public int $physical;

    #[ORM\Column]
    public int $reserved;

    #[ORM\Column]
    public int $version;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public readonly string $variantId,
    ) {
    }
}
