<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

use App\Shared\Domain\BoundedCount;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\ExtendedProblem;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * Only a category without subcategories and without products (main or additional, drafts too) is deleted (decision
 * P9). The problem tells how many of each it has, counted up to a limit.
 */
final class CategoryNotEmpty extends \DomainException implements Conflict, TypedProblem, ExtendedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/category-not-empty';

    public function __construct(public readonly BoundedCount $subcategories, public readonly BoundedCount $products)
    {
        parent::__construct('Przenieś produkty do innej kategorii, zanim usuniesz tę.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }

    public function problemExtensions(): array
    {
        return [
            'subcategoryCount' => ['value' => $this->subcategories->value, 'capped' => $this->subcategories->capped],
            'productCount' => ['value' => $this->products->value, 'capped' => $this->products->capped],
        ];
    }
}
