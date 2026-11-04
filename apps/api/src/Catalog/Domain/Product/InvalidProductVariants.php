<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * The variants of a product break a rule. The message never contains the submitted values.
 */
final class InvalidProductVariants extends \DomainException implements InvalidInput
{
    public static function countOutOfRange(): self
    {
        return new self(\sprintf('A product has between 1 and %d variants.', ProductVariants::MAX_VARIANTS));
    }

    public static function valueCountDiffersFromAxes(): self
    {
        return new self('A variant must have exactly one value per variant axis.');
    }

    public static function moreThanOneDefaultVariant(): self
    {
        return new self('A product without variant axes has exactly one variant.');
    }

    public static function idRepeated(): self
    {
        return new self('The variants of a product must have different ids.');
    }
}
