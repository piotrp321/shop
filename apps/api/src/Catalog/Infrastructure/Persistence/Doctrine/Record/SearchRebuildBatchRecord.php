<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A batch of a run that was written and counted, once: the key makes a repeated delivery count nothing. Only defines
 * the schema (DbalSearchRebuildRuns writes it with INSERT … ON CONFLICT DO NOTHING).
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_search_rebuild_batch')]
class SearchRebuildBatchRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: SearchRebuildRunRecord::class)]
        #[ORM\JoinColumn(name: 'run_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        public readonly SearchRebuildRunRecord $run,
        #[ORM\Id]
        #[ORM\Column(type: Types::INTEGER)]
        public readonly int $batch,
        /** The SHA-256 of the ids of the batch as the producer sent them, joined by commas. */
        #[ORM\Column(name: 'ids_hash', type: Types::STRING, length: 64, options: ['fixed' => true])]
        public readonly string $idsHash,
        /** The documents the batch wrote; null until it is counted. */
        #[ORM\Column(type: Types::INTEGER, nullable: true)]
        public readonly ?int $documents = null,
    ) {
    }
}
