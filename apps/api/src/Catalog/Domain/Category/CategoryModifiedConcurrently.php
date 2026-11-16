<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * The category changed since the version the change is based on.
 */
final class CategoryModifiedConcurrently extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/category-modified';

    public function __construct()
    {
        parent::__construct('Kategoria została w międzyczasie zmieniona. Odśwież drzewo i spróbuj ponownie.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
