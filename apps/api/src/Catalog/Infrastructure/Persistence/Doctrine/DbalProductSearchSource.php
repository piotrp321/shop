<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Application\Port\ProductSearchSource;
use App\Catalog\Application\Search\ProductSearchSnapshot;
use App\Catalog\Domain\Product\ProductId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class DbalProductSearchSource implements ProductSearchSource
{
    // One statement per page. The CTE applies LIMIT to catalog_product alone; categories are joined only to
    // those rows, so LIMIT never counts joined rows. The main category is never in catalog_product_category.
    // {cursor} is replaced with one of the three constant fragments below, never with input. The cursor is a plain
    // "id > CAST(:after AS uuid)" so it stays an index condition under a generic (prepared, pooled) plan too; an
    // "IS NULL OR" form would not, and paging a large catalog would scan from the start on every page.
    private const string PAGE_SQL = <<<'SQL'
        WITH page AS (
            SELECT id, version, name, slug, short_description, primary_category_id, images
            FROM catalog_product
            {cursor}
            ORDER BY id
            LIMIT :limit
        )
        SELECT p.id, p.version, p.name, p.slug, p.short_description, p.primary_category_id, p.images->0 AS main_image,
               main.path AS main_path, COALESCE(extra.ids, '[]') AS additional_ids, COALESCE(extra.paths, '[]') AS additional_paths
        FROM page p
        JOIN catalog_category main ON main.id = p.primary_category_id
        LEFT JOIN LATERAL (
            SELECT json_agg(pc.category_id ORDER BY pc.category_id) AS ids, json_agg(c.path ORDER BY pc.category_id) AS paths
            FROM catalog_product_category pc
            JOIN catalog_category c ON c.id = pc.category_id
            WHERE pc.product_id = p.id
        ) extra ON true
        ORDER BY p.id
        SQL;

    private const string FIRST_PAGE_CURSOR = '';
    private const string NEXT_PAGE_CURSOR = 'WHERE id > CAST(:after AS uuid)';
    private const string BY_ID = 'WHERE id = CAST(:id AS uuid)';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * The statement for one page; exposed so a test can pin its query plan.
     */
    public static function pageSql(bool $afterCursor): string
    {
        return str_replace('{cursor}', $afterCursor ? self::NEXT_PAGE_CURSOR : self::FIRST_PAGE_CURSOR, self::PAGE_SQL);
    }

    public function find(string $id): ?ProductSearchSnapshot
    {
        $row = $this->connection->executeQuery(
            str_replace('{cursor}', self::BY_ID, self::PAGE_SQL),
            ['id' => ProductId::fromString($id)->toString(), 'limit' => 1],
            ['id' => ParameterType::STRING, 'limit' => ParameterType::INTEGER],
        )->fetchAssociative();

        return false === $row ? null : $this->toSnapshot($row);
    }

    public function page(?string $afterId, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The page limit must be at least 1.');
        }

        // ProductId validates the UUID format and lower-cases it; the database never sees a malformed cursor.
        $after = null === $afterId ? null : ProductId::fromString($afterId)->toString();

        $rows = null === $after
            ? $this->connection->executeQuery(
                self::pageSql(false),
                ['limit' => $limit],
                ['limit' => ParameterType::INTEGER],
            )->fetchAllAssociative()
            : $this->connection->executeQuery(
                self::pageSql(true),
                ['after' => $after, 'limit' => $limit],
                ['after' => ParameterType::STRING, 'limit' => ParameterType::INTEGER],
            )->fetchAllAssociative();

        $snapshots = [];
        foreach ($rows as $row) {
            $snapshots[] = $this->toSnapshot($row);
        }

        return $snapshots;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toSnapshot(array $row): ProductSearchSnapshot
    {
        \assert(\is_string($row['id']) && \is_string($row['name']) && \is_string($row['slug']) && \is_string($row['short_description']));
        \assert(\is_string($row['primary_category_id']) && \is_string($row['main_path']));
        \assert(is_numeric($row['version']) && \is_string($row['additional_ids']) && \is_string($row['additional_paths']));

        /** @var list<string> $additionalIds */
        $additionalIds = json_decode($row['additional_ids'], true, flags: \JSON_THROW_ON_ERROR);
        /** @var list<string> $additionalPaths */
        $additionalPaths = json_decode($row['additional_paths'], true, flags: \JSON_THROW_ON_ERROR);
        $mainImage = null;
        if (\is_string($row['main_image'])) {
            /** @var array{key: string, alt: string} $mainImage */
            $mainImage = json_decode($row['main_image'], true, flags: \JSON_THROW_ON_ERROR);
        }

        return new ProductSearchSnapshot(
            $row['id'],
            (int) $row['version'],
            $row['name'],
            $row['slug'],
            $row['short_description'],
            $row['primary_category_id'],
            $additionalIds,
            [self::parsePath($row['main_path']), ...array_map(self::parsePath(...), $additionalPaths)],
            null === $mainImage ? null : ['key' => $mainImage['key'], 'alt' => $mainImage['alt']],
        );
    }

    /**
     * @return list<string> ids from the root to the category inclusive
     */
    private static function parsePath(string $path): array
    {
        return explode('/', trim($path, '/'));
    }
}
