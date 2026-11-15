<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence;

use App\Shared\Domain\BoundedCount;
use Doctrine\DBAL\Connection;

/**
 * Counts the rows of a list at most up to a limit: `SELECT count(*) FROM (SELECT 1 FROM … WHERE … LIMIT limit + 1) t`.
 * The subquery stops after limit + 1 rows, so the cost depends on the limit, never on the size of the table (a bare
 * `count(*)` reads every matching row). Give it the conditions of the page without the cursor and the order, so the
 * count is of the whole result the pages walk through, on the same index as the page.
 *
 * The caller's fragments are constants of the code (a table with its alias, conditions joined by AND); input only
 * reaches the statement as parameters.
 */
final readonly class BoundedCounter
{
    public const int DEFAULT_LIMIT = 10_000;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param string                                              $from       the FROM clause without the keyword, e.g. `catalog_product p`
     * @param list<string>                                        $conditions each one a condition of the page, ANDed
     * @param array<string, mixed>                                $parameters the values the conditions bind
     * @param array<string, \Doctrine\DBAL\ParameterType|string|int> $types
     */
    public function count(string $from, array $conditions, array $parameters = [], array $types = [], int $limit = self::DEFAULT_LIMIT): BoundedCount
    {
        $rows = $this->connection->fetchOne(self::sql($from, $conditions, $limit), $parameters, $types);
        \assert(\is_int($rows));

        return BoundedCount::ofRowsRead($rows, $limit);
    }

    /**
     * @param list<string> $conditions
     */
    public static function sql(string $from, array $conditions, int $limit): string
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The limit of a count must be at least 1.');
        }

        // The limit is an int, so it can go into the statement as a literal: the planner sees the row goal.
        return 'SELECT count(*) FROM (SELECT 1 FROM '.$from
            .([] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions))
            .' LIMIT '.($limit + 1).') bounded';
    }
}
