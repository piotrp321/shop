<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Application\Port\SitemapChunkRead;
use App\Catalog\Application\Port\SitemapProductSource;
use App\Catalog\Application\Query\SitemapChunkKey;
use App\Catalog\Domain\Product\ProductStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;

/**
 * Reads the sitemap products with DBAL, both statements on the primary key of `catalog_product`: the boundaries are
 * a scan of the key (no table access), a chunk is a range of the key with a limit.
 *
 * The boundaries are cut by keyset (spec docs/features/admin-queries/spec.md, M1): one short statement per chunk, each
 * from the previous boundary, all in one read-only REPEATABLE READ transaction of their own, so they see one state of
 * the data whatever is inserted or deleted meanwhile (G2); a caller that already holds a transaction gets the cut in it. A chunk holds exactly N products of that state, so it never holds more
 * than the row limit (G1), and no statistics of the table are read.
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
        // A chunk of more than the row limit would be cut short when it is read.
        $step = max(1, min($chunkSize, $this->rowLimit));

        if ($this->connection->isTransactionActive()) {
            // The cut joins the transaction of the caller: it sees one state of the data only as far as that transaction
            // keeps one snapshot (the port says so); the isolation level of a transaction cannot be changed after its
            // first query.
            $ids = $this->cutByKeyset($step);
        } else {
            $this->connection->beginTransaction();
            try {
                $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                $ids = $this->cutByKeyset($step);
            } catch (\Throwable $failure) {
                $this->connection->rollBack();

                throw $failure;
            }
            $this->connection->commit();
        }

        return array_map(static fn (string $id): string => strtolower($id), $ids);
    }

    /**
     * @return list<string>
     */
    private function cutByKeyset(int $step): array
    {
        $ids = [];
        $id = $this->connection->fetchOne('SELECT id FROM catalog_product ORDER BY id OFFSET :skip LIMIT 1', ['skip' => $step], ['skip' => ParameterType::INTEGER]);
        while (\is_string($id)) {
            $ids[] = $id;
            $id = $this->connection->fetchOne(
                'SELECT id FROM catalog_product WHERE id > CAST(:previous AS uuid) ORDER BY id OFFSET :skip LIMIT 1',
                ['previous' => $id, 'skip' => $step - 1],
                ['skip' => ParameterType::INTEGER],
            );
        }

        return $ids;
    }

    public function publishedInRange(SitemapChunkKey $chunk): SitemapChunkRead
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
        $overflowed = \count($rows) > $this->rowLimit;
        if ($overflowed) {
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

        return new SitemapChunkRead($products, $overflowed);
    }
}
