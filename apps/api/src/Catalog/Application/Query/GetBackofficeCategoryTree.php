<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class GetBackofficeCategoryTree
{
    public function __construct(public bool $withCounts = false)
    {
    }
}
