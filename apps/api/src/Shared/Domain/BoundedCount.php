<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * How many rows a list matches, counted up to a limit so that the cost does not grow with the table: `value` is
 * exact unless `capped`, when there are more than `value` rows. The API answers it as BoundedCountResponse.
 */
final readonly class BoundedCount
{
    public function __construct(
        public int $value,
        public bool $capped,
    ) {
        if ($value < 0) {
            throw new \InvalidArgumentException('A count cannot be negative.');
        }
    }

    /**
     * The count from the number of rows a read of at most $limit + 1 rows returned: the extra row only shows that the
     * count stopped at the limit.
     */
    public static function ofRowsRead(int $rows, int $limit): self
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The limit of a count must be at least 1.');
        }
        if ($rows < 0 || $rows > $limit + 1) {
            throw new \InvalidArgumentException('A read of at most limit + 1 rows cannot return '.$rows.' rows.');
        }

        return new self(min($rows, $limit), $rows > $limit);
    }
}
