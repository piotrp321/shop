<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\CategoryReindexRun;
use App\Catalog\Application\Port\CategoryReindexRuns;
use App\Catalog\Domain\Category\CategoryId;

/**
 * Runs in memory; records the calls in order ("start", "lock", "advance", "finish").
 */
final class InMemoryCategoryReindexRuns implements CategoryReindexRuns
{
    /** @var array<string, CategoryReindexRun> */
    public array $runs = [];
    /** @var array<string, \DateTimeImmutable> */
    public array $finishedAt = [];
    /** @var list<string> */
    public array $calls = [];
    private int $next = 0;

    public function start(CategoryId $categoryId): string
    {
        $this->calls[] = 'start';
        $id = \sprintf('00000000-0000-4000-8000-%012d', ++$this->next);
        $this->runs[$id] = new CategoryReindexRun($id, $categoryId->toString(), null, 0, false);

        return $id;
    }

    public function lock(string $runId): ?CategoryReindexRun
    {
        $this->calls[] = 'lock';

        return $this->runs[$runId] ?? null;
    }

    public function advance(string $runId, string $cursor, int $nextBatch): void
    {
        $this->calls[] = 'advance';
        $run = $this->runs[$runId];
        $this->runs[$runId] = new CategoryReindexRun($run->id, $run->categoryId, $cursor, $nextBatch, false);
    }

    public function finish(string $runId, \DateTimeImmutable $at): void
    {
        $this->calls[] = 'finish';
        $run = $this->runs[$runId];
        $this->runs[$runId] = new CategoryReindexRun($run->id, $run->categoryId, $run->cursor, $run->nextBatch, true);
        $this->finishedAt[$runId] = $at;
    }
}
