<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * A product id of the price list combined with a search, a category, a sort or a cursor: one product is not paged. The
 * message never contains the submitted values.
 */
final class InvalidPriceList extends \DomainException implements InvalidInput
{
    public static function productWithFilters(): self
    {
        return new self('The product cannot be combined with a search, a category, a sort or a cursor.');
    }
}
