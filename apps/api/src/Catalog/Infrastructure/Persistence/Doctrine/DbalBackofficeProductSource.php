<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Application\Port\BackofficeProductSource;
use App\Catalog\Application\Query\BackofficeProduct;
use App\Catalog\Application\Query\ProductCursor;
use App\Catalog\Domain\Category\CategoryPath;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class DbalBackofficeProductSource implements BackofficeProductSource
{
    // The conditions are constant fragments joined by AND; input only ever reaches the statement as parameters.
    // The name term (ILIKE '%…%') is served by the trigram index from 3 characters on; pg_trgm extracts no trigram from
    // a 2-character term, so those run as a scan of the (name, id) index with the term as a filter. The order and the
    // keyset cursor are served by the (name, id) index. The cursor is a row comparison, which keeps it an index condition. A category matches when the product's
    // main category or one of its additional categories is in the subtree: the path of every category starts with
    // the path of its ancestors (materialized path), so the subtree is a prefix match on the path.
    private const string TERM = 'p.name ILIKE :pattern';
    private const string CURSOR = '(p.name, p.id) > (:after_name, CAST(:after_id AS uuid))';
    private const string CATEGORY = <<<'SQL'
        (
            p.primary_category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :prefix)
            OR EXISTS (
                SELECT 1 FROM catalog_product_category pc
                WHERE pc.product_id = p.id AND pc.category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :prefix)
            )
        )
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    public function page(?string $term, ?CategoryPath $category, ?ProductCursor $after, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The page limit must be at least 1.');
        }

        $conditions = [];
        $parameters = ['limit' => $limit];
        $types = ['limit' => ParameterType::INTEGER];
        if (null !== $term) {
            $conditions[] = self::TERM;
            $parameters['pattern'] = '%'.self::escapeLike($term).'%';
        }
        if (null !== $category) {
            $conditions[] = self::CATEGORY;
            $parameters['prefix'] = self::escapeLike($category->toString()).'%';
        }
        if (null !== $after) {
            $conditions[] = self::CURSOR;
            $parameters['after_name'] = $after->name;
            $parameters['after_id'] = $after->id;
        }

        $sql = 'SELECT p.id, p.name, p.tax_class FROM catalog_product p'
            .([] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions))
            .' ORDER BY p.name, p.id LIMIT :limit';

        return array_map(static function (array $row): BackofficeProduct {
            \assert(\is_string($row['id']) && \is_string($row['name']) && \is_string($row['tax_class']));

            return new BackofficeProduct($row['id'], $row['name'], $row['tax_class']);
        }, $this->connection->executeQuery($sql, $parameters, $types)->fetchAllAssociative());
    }

    /**
     * Makes `%`, `_` and the escape character `\` (PostgreSQL's default) match themselves in LIKE and ILIKE.
     */
    private static function escapeLike(string $text): string
    {
        return strtr($text, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }
}
