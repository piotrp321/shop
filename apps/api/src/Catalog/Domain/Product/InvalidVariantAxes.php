<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * The axes of a product break a rule. The message never contains the submitted names.
 */
final class InvalidVariantAxes extends \DomainException implements InvalidInput
{
    public static function tooMany(): self
    {
        return new self(\sprintf('A product has at most %d variant axes.', VariantAxes::MAX_AXES));
    }

    public static function nameLengthOutOfRange(): self
    {
        return new self(\sprintf('The name of a variant axis must have between 1 and %d characters.', VariantAxes::MAX_NAME_LENGTH));
    }

    public static function nameWithForbiddenCharacters(): self
    {
        return new self('The name of a variant axis must not contain forbidden characters.');
    }

    public static function nameWithColon(): self
    {
        return new self('The name of a variant axis must not contain a colon.');
    }

    public static function nameRepeated(): self
    {
        return new self('The names of the variant axes must be different.');
    }
}
