<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * The parent named for a new or moved category does not exist: a wrong value of the request, not a missing resource.
 */
final class ParentCategoryNotFound extends \DomainException implements InvalidInput
{
    public function __construct()
    {
        parent::__construct('Kategoria nadrzędna nie istnieje.');
    }
}
