<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * Another child of the same parent (another root for a root) has the slug: the address of the category would not be
 * unique.
 */
final class CategorySlugTaken extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/category-slug-taken';

    public function __construct()
    {
        parent::__construct('Inna kategoria w tym miejscu drzewa ma już ten slug.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
