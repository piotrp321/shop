<?php

declare(strict_types=1);

namespace App\Tests\Support\InMemory;

use App\Catalog\Application\Port\ProductIndexer;
use App\Catalog\Application\Search\IndexOutcome;
use App\Catalog\Application\Search\ProductDocument;

/**
 * Answers countDocuments() with the given counts in turn; the last one repeats.
 */
final class FixedCountProductIndexer implements ProductIndexer
{
    public int $polls = 0;

    /**
     * @param non-empty-list<int> $counts
     */
    public function __construct(private readonly array $counts)
    {
    }

    public function index(ProductDocument $document): IndexOutcome
    {
        throw new \LogicException('The seed never indexes directly.');
    }

    public function countDocuments(): int
    {
        return $this->counts[min($this->polls++, \count($this->counts) - 1)];
    }
}
