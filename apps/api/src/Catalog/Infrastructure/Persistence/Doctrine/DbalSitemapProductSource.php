<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Application\Port\SitemapProductSource;
use App\Catalog\Application\Query\SitemapChunkKey;
use App\Catalog\Domain\Product\ProductStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;

/**
 * Reads the sitemap products with DBAL, both statements on the primary key of `catalog_product`: the boundaries are
 * a scan of the key (no table access), a chunk is a range of the key with a limit.
 */
final readonly class DbalSitemapProductSource implements SitemapProductSource
{
    public function __construct(
        private Connection $connection,
        private int $rowLimit,
        private LoggerInterface $logger,
    ) {
    }

    public function boundaries(int $chunkSize): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM (SELECT id, row_number() OVER (ORDER BY id) AS position FROM catalog_product) numbered
             WHERE position > 1 AND (position - 1) % :size = 0
             ORDER BY position',
            ['size' => $chunkSize],
            ['size' => ParameterType::INTEGER],
        );

        return array_map(static function (mixed $id): string {
            \assert(\is_string($id));

            return strtolower($id);
        }, $ids);
    }

    public function publishedInRange(SitemapChunkKey $chunk): array
    {
        $conditions = [];
        $params = ['limit' => $this->rowLimit + 1];
        if (null !== $chunk->from) {
            $conditions[] = 'id >= CAST(:from AS uuid)';
            $params['from'] = $chunk->from;
        }
        if (null !== $chunk->to) {
            $conditions[] = 'id < CAST(:to AS uuid)';
            $params['to'] = $chunk->to;
        }

        // The limit applies to the range before the status is looked at, so drafts count and the read stays bounded.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT slug, status, updated_at FROM catalog_product'
            .([] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions))
            .' ORDER BY id LIMIT :limit',
            $params,
            ['limit' => ParameterType::INTEGER],
        );
        if (\count($rows) > $this->rowLimit) {
            $this->logger->warning('A sitemap chunk has more than the row limit of products; the products beyond it are left out until the chunks are cut again.', ['limit' => $this->rowLimit]);
            $rows = \array_slice($rows, 0, $this->rowLimit);
        }

        $products = [];
        foreach ($rows as $row) {
            \assert(\is_string($row['slug']) && \is_string($row['status']) && \is_string($row['updated_at']));
            if (ProductStatus::Published->value === $row['status']) {
                $products[] = ['slug' => $row['slug'], 'updatedAt' => new \DateTimeImmutable($row['updated_at'])];
            }
        }

        return $products;
    }
}
