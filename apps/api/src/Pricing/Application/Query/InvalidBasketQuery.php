<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * A basket without a line, with more than 50, with a line that is no variant id, of 0 or more than 99 units or of a
 * variant already in another line, or a code longer than 64 characters. The message never contains the submitted
 * value.
 */
final class InvalidBasketQuery extends \DomainException implements InvalidInput
{
    public static function lines(): self
    {
        return new self('A basket has 1 to 50 lines of distinct variants (UUIDs) of 1 to 99 units.');
    }

    public static function codeTooLong(): self
    {
        return new self(\sprintf('A discount code has at most %d characters.', QuoteBasket::MAX_CODE_LENGTH));
    }
}
