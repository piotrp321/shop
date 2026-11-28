<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search\AsyncRebuild;

use App\Catalog\Application\Port\SearchIndexMaintenance;
use App\Catalog\Application\Search\ActivationResult;
use App\Catalog\Application\Search\BulkResult;
use App\Catalog\Application\Search\SetupResult;

/**
 * The real maintenance of Elasticsearch with a hook before and after the next bulk write of a batch: a test runs an
 * abort during the write, fails the write once, or ends the worker right after the write (before the count). Each hook
 * runs once.
 */
final class HookedMaintenance implements SearchIndexMaintenance
{
    /** @var (\Closure(): void)|null */
    public ?\Closure $beforeWrite = null;
    /** @var (\Closure(): void)|null */
    public ?\Closure $afterWrite = null;

    public function __construct(private readonly SearchIndexMaintenance $inner)
    {
    }

    public function setup(): SetupResult
    {
        return $this->inner->setup();
    }

    public function recreate(): void
    {
        $this->inner->recreate();
    }

    public function activeIndex(): string
    {
        return $this->inner->activeIndex();
    }

    public function createIndex(): string
    {
        return $this->inner->createIndex();
    }

    public function write(string $index, array $documents): BulkResult
    {
        if (null !== $this->beforeWrite) {
            $hook = $this->beforeWrite;
            $this->beforeWrite = null;
            $hook();
        }
        $result = $this->inner->write($index, $documents);
        if (null !== $this->afterWrite) {
            $hook = $this->afterWrite;
            $this->afterWrite = null;
            $hook();
        }

        return $result;
    }

    public function verify(string $index, int $expectedDocuments): void
    {
        $this->inner->verify($index, $expectedDocuments);
    }

    public function activate(string $index, string $expectedCurrent): ActivationResult
    {
        return $this->inner->activate($index, $expectedCurrent);
    }

    public function deleteIndex(string $index): void
    {
        $this->inner->deleteIndex($index);
    }
}
