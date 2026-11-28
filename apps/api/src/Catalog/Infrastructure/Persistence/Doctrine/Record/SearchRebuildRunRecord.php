<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A run of the asynchronous full rebuild of the search index (docs/features/async-indexing/spec.md, "Przebieg"). Only
 * defines the schema: DbalSearchRebuildRuns reads and changes the rows by DBAL with conditional updates. A run is active
 * until finished_at is set (a final status); the partial unique index allows one active run per document type.
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_search_rebuild_run')]
#[ORM\UniqueConstraint(name: self::ACTIVE_INDEX, columns: ['document_type'], options: ['where' => '(finished_at IS NULL)'])]
class SearchRebuildRunRecord
{
    public const string ACTIVE_INDEX = 'catalog_search_rebuild_run_active_uniq';

    #[ORM\Column(type: Types::STRING, length: 16)]
    public string $status = 'producing';

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    public ?int $totalBatches = null;

    #[ORM\Column(type: Types::INTEGER)]
    public int $doneBatches = 0;

    #[ORM\Column(type: Types::BIGINT)]
    public int $documents = 0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $failure = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::GUID)]
        public readonly string $id,
        #[ORM\Column(type: Types::STRING, length: 32)]
        public readonly string $documentType,
        #[ORM\Column(type: Types::STRING, length: 255)]
        public readonly string $targetIndex,
        #[ORM\Column(type: Types::STRING, length: 255)]
        public readonly string $previousIndex,
        #[ORM\Column(type: Types::INTEGER)]
        public readonly int $batchSize,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public readonly \DateTimeImmutable $startedAt,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public \DateTimeImmutable $heartbeatAt,
    ) {
    }
}
