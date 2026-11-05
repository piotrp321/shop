<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * The query names no variant, too many, or something that is not a variant id. The message never contains the
 * submitted value.
 */
final class InvalidStockLevelsQuery extends \DomainException implements InvalidInput
{
    public static function variantCountOutOfRange(): self
    {
        return new self(\sprintf('Between 1 and %d variants must be given.', GetStockLevels::MAX_VARIANTS));
    }

    public static function notAVariantId(): self
    {
        return new self('A variant id must be a UUID.');
    }
}
