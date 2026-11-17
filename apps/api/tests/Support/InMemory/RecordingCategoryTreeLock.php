<?php

declare(strict_types=1);

namespace App\Tests\Support\InMemory;

use App\Catalog\Application\Port\CategoryTreeLock;

final class RecordingCategoryTreeLock implements CategoryTreeLock
{
    public int $taken = 0;

    /** Whether the last lock came before any read of the categories since the test reset their reads. */
    public bool $takenBeforeTheFirstRead = false;

    public function __construct(private readonly InMemoryCategoryRepository $categories)
    {
    }

    public function exclusive(): void
    {
        ++$this->taken;
        $this->takenBeforeTheFirstRead = 0 === $this->categories->reads;
    }
}
