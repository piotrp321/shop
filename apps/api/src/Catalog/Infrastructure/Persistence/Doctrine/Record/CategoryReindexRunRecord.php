<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A run of the reindexing of a category subtree: the last product id of the batches done, the number of the batch
 * it expects next, and when it finished. Only defines the schema: DbalCategoryReindexRuns reads, locks and writes the
 * rows by DBAL. No foreign key to the category: a run of a deleted (empty) category finds no products and finishes.
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_category_reindex')]
class CategoryReindexRunRecord
{
    #[ORM\Column(type: Types::GUID, nullable: true)]
    public ?string $cursor = null;

    #[ORM\Column(type: Types::INTEGER)]
    public int $nextBatch = 0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $finishedAt = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::GUID)]
        public readonly string $id,
        #[ORM\Column(type: Types::GUID)]
        public readonly string $categoryId,
    ) {
    }
}
