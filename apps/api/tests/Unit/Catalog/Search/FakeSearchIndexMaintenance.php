<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Search;

use App\Catalog\Application\Port\SearchIndexMaintenance;
use App\Catalog\Application\Search\ActivationResult;
use App\Catalog\Application\Search\BulkResult;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchIndexError;

/**
 * Records every call; each step can be told to fail.
 */
final class FakeSearchIndexMaintenance implements SearchIndexMaintenance
{
    /** @var list<string> method names in call order */
    public array $calls = [];
    /** @var array<string, list<list<ProductDocument>>> batches written, per index */
    public array $written = [];
    /** @var list<string> */
    public array $deleted = [];
    /** @var list<array{string, int}> */
    public array $verified = [];
    /** @var list<array{string, string}> */
    public array $activations = [];

    public ?\Throwable $activeIndexFailure = null;
    public ?\Throwable $writeFailure = null;
    public ?BulkResult $writeResult = null;
    public ?\Throwable $verifyFailure = null;
    public ActivationResult $activationResult = ActivationResult::Activated;
    /** @var list<string> */
    public array $undeletable = [];

    public function __construct(
        private string $activeIndex = 'products_old',
        private string $newIndex = 'products_new',
    ) {
    }

    public function setup(): array
    {
        $this->calls[] = 'setup';

        return [];
    }

    public function recreate(): void
    {
        $this->calls[] = 'recreate';
    }

    public function activeIndex(): string
    {
        $this->calls[] = 'activeIndex';
        if (null !== $this->activeIndexFailure) {
            throw $this->activeIndexFailure;
        }

        return $this->activeIndex;
    }

    public function createIndex(): string
    {
        $this->calls[] = 'createIndex';

        return $this->newIndex;
    }

    public function write(string $index, array $documents): BulkResult
    {
        $this->calls[] = 'write';
        if (null !== $this->writeFailure) {
            throw $this->writeFailure;
        }
        $this->written[$index][] = $documents;

        return $this->writeResult ?? new BulkResult(indexed: array_map(static fn (ProductDocument $d): string => $d->id, $documents));
    }

    public function verify(string $index, int $expectedDocuments): void
    {
        $this->calls[] = 'verify';
        $this->verified[] = [$index, $expectedDocuments];
        if (null !== $this->verifyFailure) {
            throw $this->verifyFailure;
        }
    }

    public function activate(string $index, string $expectedCurrent): ActivationResult
    {
        $this->calls[] = 'activate';
        $this->activations[] = [$index, $expectedCurrent];

        return $this->activationResult;
    }

    public function deleteIndex(string $index): void
    {
        $this->calls[] = 'deleteIndex';
        if (\in_array($index, $this->undeletable, true)) {
            throw new SearchIndexError(\sprintf('Index "%s" could not be deleted.', $index));
        }
        $this->deleted[] = $index;
    }
}
