<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Exception\Conflict;

final class ProductModifiedConcurrently extends \DomainException implements Conflict
{
    public function __construct()
    {
        parent::__construct('The product was modified concurrently. Reload it and try again.');
    }
}
