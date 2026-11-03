<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Stock;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * The message never contains the submitted value.
 */
final class InvalidStockQuantity extends \DomainException implements InvalidInput
{
    public static function receipt(): self
    {
        return new self(\sprintf('A receipt must add 1 to %d units.', StockItem::MAX_RECEIPT));
    }

    public static function count(): self
    {
        return new self(\sprintf('A counted stock must be 0 to %d units.', StockItem::MAX_PHYSICAL));
    }
}
