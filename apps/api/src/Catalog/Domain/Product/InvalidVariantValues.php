<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * A value of a variant breaks a rule. The message never contains the submitted value.
 */
final class InvalidVariantValues extends \DomainException implements InvalidInput
{
    public static function lengthOutOfRange(): self
    {
        return new self(\sprintf('A variant value must have between 1 and %d characters.', VariantValues::MAX_VALUE_LENGTH));
    }

    public static function forbiddenCharacters(): self
    {
        return new self('A variant value must not contain forbidden characters.');
    }
}
