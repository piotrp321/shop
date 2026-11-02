<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Stock;

/**
 * A state or an operation that would break 0 ≤ reserved ≤ physical ≤ 1 000 000. Never caused by a valid request:
 * it means the stored stock and the reservations disagree, so it is not a problem shown to API clients.
 */
final class StockInvariantViolation extends \LogicException
{
}
