<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * A level of the stock list that is none of the two it is filtered by, or one combined with a search, a category or a
 * sort: the level is paged by the stock records alone. The message never contains the submitted values.
 */
final class InvalidStockList extends \DomainException implements InvalidInput
{
    public static function unknownLevel(): self
    {
        return new self('The level must be out_of_stock or low_stock.');
    }

    public static function productWithFilters(): self
    {
        return new self('The product cannot be combined with a search, a category, a level, a sort or a cursor.');
    }

    public static function levelWithFilters(): self
    {
        return new self('The level cannot be combined with a search, a category or a sort.');
    }
}
