<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Exception\InvalidInput;

final class DuplicateVariantValues extends \DomainException implements InvalidInput
{
    public function __construct()
    {
        parent::__construct('Two variants of a product have the same values.');
    }
}
