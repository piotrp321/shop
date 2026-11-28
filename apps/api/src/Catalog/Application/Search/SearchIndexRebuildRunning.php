<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * A product cannot be created while the search index is rebuilt: the rebuild checks that its new index holds exactly
 * the products it wrote, and a product created meanwhile would be one more (docs/features/search/spec.md, "Zapisy
 * w trakcie przebudowy").
 */
final class SearchIndexRebuildRunning extends \RuntimeException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/search-rebuild-running';

    public function __construct()
    {
        parent::__construct('Trwa przebudowa indeksu wyszukiwarki. Dodawanie produktów jest wstrzymane do jej końca, spróbuj ponownie później.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
