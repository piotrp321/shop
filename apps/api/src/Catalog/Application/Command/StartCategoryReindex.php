<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * Starts the reindexing of the products of the subtree of a category: a new run and the message of its first batch,
 * in one transaction of command.bus.
 */
final readonly class StartCategoryReindex
{
    public function __construct(public string $categoryId)
    {
    }
}
