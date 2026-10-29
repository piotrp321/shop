<?php

declare(strict_types=1);

namespace App\Tests\Support\InMemory;

use App\Catalog\Application\Port\ProductIndexer;
use App\Catalog\Application\Search\IndexOutcome;
use App\Catalog\Application\Search\ProductDocument;

/**
 * Spy for ProductIndexer: records every document and answers with the queued outcomes (Indexed when none is queued).
 * A queued Throwable is thrown instead.
 */
final class RecordingProductIndexer implements ProductIndexer
{
    /** @var list<ProductDocument> */
    public array $indexed = [];

    /** @var list<IndexOutcome|\Throwable> */
    private array $answers = [];

    public function willReturn(IndexOutcome|\Throwable ...$answers): void
    {
        $this->answers = array_values($answers);
    }

    public function index(ProductDocument $document): IndexOutcome
    {
        $this->indexed[] = $document;
        $answer = array_shift($this->answers) ?? IndexOutcome::Indexed;
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return $answer;
    }

    public function countDocuments(): int
    {
        return \count($this->indexed);
    }
}
