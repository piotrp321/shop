<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine\BackofficeProductList;

use App\Catalog\Application\Query\ProductCursor;
use App\Catalog\Application\Query\ProductSort;

/**
 * The keyset condition of a page of the product list: the rows after the cursor in the order of the sort. It is a row
 * comparison in the direction of the sort, which keeps it an index condition of (name, id) or (updated_at, id).
 */
final class Keyset
{
    /**
     * @param string $alias the alias of catalog_product in the statement
     *
     * @return array{string, array{after_value: string, after_id: string}} the condition and the values it binds
     */
    public static function after(string $alias, ProductSort $sort, ProductCursor $after): array
    {
        if ($after->sort !== $sort) {
            throw new \InvalidArgumentException('The cursor is of another sort.');
        }
        $operator = $sort->isDescending() ? '<' : '>';
        $condition = $sort->byUpdatedAt()
            ? "({$alias}.updated_at, {$alias}.id) {$operator} (CAST(:after_value AS timestamptz), CAST(:after_id AS uuid))"
            : "({$alias}.name, {$alias}.id) {$operator} (:after_value, CAST(:after_id AS uuid))";

        return [$condition, [
            'after_value' => $after->value instanceof \DateTimeImmutable ? $after->value->format('Y-m-d H:i:s.uP') : $after->value,
            'after_id' => $after->id,
        ]];
    }
}
