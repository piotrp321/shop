<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * The search term, the page size or the category id is out of range. The message never contains the submitted value.
 */
final class InvalidBackofficeProductSearch extends \DomainException implements InvalidInput
{
    public static function termLengthOutOfRange(): self
    {
        return new self(\sprintf(
            'The search term must have between %d and %d characters.',
            SearchBackofficeProducts::MIN_TERM_LENGTH,
            SearchBackofficeProducts::MAX_TERM_LENGTH,
        ));
    }

    public static function termWithNul(): self
    {
        return new self('The search term must not contain a NUL character.');
    }

    public static function perPageOutOfRange(): self
    {
        return new self(\sprintf('The page size must be between 1 and %d.', SearchBackofficeProducts::MAX_PER_PAGE));
    }

    public static function unknownStatus(): self
    {
        return new self('The status must be draft or published.');
    }

    public static function notACategoryId(): self
    {
        return new self('A category id must be a UUID.');
    }
}
