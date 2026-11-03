<?php

declare(strict_types=1);

namespace App\Cart\Domain;

use App\Shared\Domain\Exception\NotFound;

/**
 * Catalog does not know the product to add or increase.
 */
final class ProductNotFound extends \DomainException implements NotFound
{
    public function __construct()
    {
        parent::__construct('Produkt nie jest już dostępny.');
    }
}
