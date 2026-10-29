<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Exception\Conflict;

final class ProductAlreadyExists extends \DomainException implements Conflict
{
    public function __construct()
    {
        parent::__construct('A product with this id already exists.');
    }
}
