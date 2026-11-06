<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

use App\Shared\Domain\Exception\Conflict;

final class CategorySlugTaken extends \DomainException implements Conflict
{
    public function __construct()
    {
        parent::__construct('A category with this slug already exists under the same parent.');
    }
}
