<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * No slug was given and none can be derived from the name (it has no letter or digit).
 */
final class SlugRequired extends \DomainException implements InvalidInput
{
    public function __construct()
    {
        parent::__construct('Podaj slug.');
    }
}
