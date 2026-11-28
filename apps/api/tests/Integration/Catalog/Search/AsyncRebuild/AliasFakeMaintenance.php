<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search\AsyncRebuild;

use App\Catalog\Application\Port\SearchIndexMaintenance;
use App\Catalog\Application\Search\ActivationResult;
use App\Catalog\Application\Search\BulkResult;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SetupResult;

/**
 * Elasticsearch as the finalization sees it: the alias on one index, indexes that exist, a switch that expects the
 * current index (as the real one), counters of the switches and deletions, and hooks that run inside a stage, so that a
 * test can pause one handler there and run another one meanwhile.
 */
final class AliasFakeMaintenance implements SearchIndexMaintenance
{
    public int $activations = 0;
    /** @var list<string> */
    public array $deleted = [];
    /** @var array<string, true> */
    public array $indexes = [];
    public ?\Throwable $verifyFailure = null;
    /** @var (\Closure(): void)|null */
    public ?\Closure $duringVerify = null;
    /** @var (\Closure(): void)|null */
    public ?\Closure $duringActivate = null;
    /** @var (\Closure(): void)|null */
    public ?\Closure $duringDelete = null;
    public bool $loseActivateAnswer = false;

    public function __construct(public string $alias, string ...$indexes)
    {
        foreach ([$alias, ...$indexes] as $index) {
            $this->indexes[$index] = true;
        }
    }

    public function setup(): SetupResult
    {
        return new SetupResult();
    }

    public function recreate(): void
    {
    }

    public function activeIndex(): string
    {
        return $this->alias;
    }

    public function createIndex(): string
    {
        $index = 'test_products_new_'.\count($this->indexes);
        $this->indexes[$index] = true;

        return $index;
    }

    public function write(string $index, array $documents): BulkResult
    {
        return new BulkResult(indexed: []);
    }

    public function verify(string $index, int $expectedDocuments): void
    {
        if (null !== $this->duringVerify) {
            $hook = $this->duringVerify;
            $this->duringVerify = null;
            $hook();
        }
        if (null !== $this->verifyFailure) {
            throw $this->verifyFailure;
        }
    }

    public function activate(string $index, string $expectedCurrent): ActivationResult
    {
        if (null !== $this->duringActivate) {
            $hook = $this->duringActivate;
            $this->duringActivate = null;
            $hook();
        }
        if ($this->alias !== $expectedCurrent) {
            return ActivationResult::NotActivated;
        }
        $this->alias = $index;
        ++$this->activations;

        return $this->loseActivateAnswer ? ActivationResult::Unknown : ActivationResult::Activated;
    }

    public function deleteIndex(string $index): void
    {
        if (null !== $this->duringDelete) {
            $hook = $this->duringDelete;
            $this->duringDelete = null;
            $hook();
        }
        if ($index === $this->alias) {
            throw new SearchIndexError('The alias points to it.');
        }
        unset($this->indexes[$index]);
        $this->deleted[] = $index;
    }
}
