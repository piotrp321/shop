<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * A category cannot move under itself or under one of its descendants.
 */
final class CategoryCycle extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/category-cycle';

    public function __construct()
    {
        parent::__construct('Nie można przenieść kategorii do niej samej ani do jej podkategorii.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
