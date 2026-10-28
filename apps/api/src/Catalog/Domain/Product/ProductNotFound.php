<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Exception\NotFound;

final class ProductNotFound extends \DomainException implements NotFound
{
    public function __construct()
    {
        parent::__construct('Product not found.');
    }
}
