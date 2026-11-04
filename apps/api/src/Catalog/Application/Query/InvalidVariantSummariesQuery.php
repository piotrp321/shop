<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * The query names no variant, too many variants, or something that is not a variant id. The message never contains
 * the submitted value.
 */
final class InvalidVariantSummariesQuery extends \DomainException implements InvalidInput
{
    public static function variantCountOutOfRange(): self
    {
        return new self(\sprintf('Between 1 and %d variants must be given.', GetVariantSummaries::MAX_VARIANTS));
    }

    public static function notAVariantId(): self
    {
        return new self('A variant id must be a UUID.');
    }
}
