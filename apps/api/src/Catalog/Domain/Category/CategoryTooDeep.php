<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * The tree would have more than {@see CategoryTree::MAX_DEPTH} levels (decision P9).
 */
final class CategoryTooDeep extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/category-too-deep';

    public function __construct()
    {
        parent::__construct('Drzewo kategorii może mieć najwyżej '.CategoryTree::MAX_DEPTH.' poziomy.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
