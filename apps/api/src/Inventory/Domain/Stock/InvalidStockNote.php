<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Stock;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * The message never contains the submitted value.
 */
final class InvalidStockNote extends \DomainException implements InvalidInput
{
    public static function tooLong(): self
    {
        return new self(\sprintf('A note may have at most %d characters.', StockNote::MAX_LENGTH));
    }

    public static function requiredForOtherReason(): self
    {
        return new self('A correction for another reason needs a note.');
    }
}
