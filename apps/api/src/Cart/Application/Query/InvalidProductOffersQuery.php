<?php

declare(strict_types=1);

namespace App\Cart\Application\Query;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * The query names no product, too many products, or something that is not a product id. The message never contains
 * the submitted value.
 */
final class InvalidProductOffersQuery extends \DomainException implements InvalidInput
{
    public static function productCountOutOfRange(): self
    {
        return new self(\sprintf('Between 1 and %d products must be given.', GetProductOffers::MAX_PRODUCTS));
    }

    public static function notAProductId(): self
    {
        return new self('A product id must be a UUID.');
    }
}
