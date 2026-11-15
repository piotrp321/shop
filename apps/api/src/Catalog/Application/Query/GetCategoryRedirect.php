<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Domain\Category\CategorySlugPath;

/**
 * Where an old address of a category leads now (spec ADMIN-FEATURES, 4 "Przekierowania").
 */
final readonly class GetCategoryRedirect
{
    public function __construct(public CategorySlugPath $path)
    {
    }
}
