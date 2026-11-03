<?php

declare(strict_types=1);

namespace App\Cart\Domain;

use App\Shared\Domain\Exception\NotFound;

/**
 * The cart has no line of the product.
 */
final class CartLineNotFound extends \DomainException implements NotFound
{
    public function __construct()
    {
        parent::__construct('Tej pozycji nie ma w koszyku.');
    }
}
