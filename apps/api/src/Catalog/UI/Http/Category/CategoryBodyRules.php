<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Category;

use App\Catalog\Domain\Category\CategoryName;
use App\Catalog\Domain\Category\CategorySlug;
use App\Catalog\Domain\Category\ParentCategoryNotFound;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The rules of the bodies of the category writes, each with one Polish violation at its field. The rules of the
 * domain are applied (CategoryName, CategorySlug), not copied.
 */
final class CategoryBodyRules
{
    public const string NAME = 'Nazwa kategorii musi mieć od 1 do 100 znaków i nie może zawierać znaków sterujących.';
    public const string NAME_TYPE = 'Nazwa kategorii musi być tekstem.';
    public const string SLUG = 'Slug może mieć 1–60 znaków: małe litery a–z i cyfry, słowa oddzielone pojedynczym myślnikiem.';
    public const string SLUG_TYPE = 'Slug musi być tekstem.';
    public const string POSITION = 'Pozycja musi być liczbą całkowitą od 0.';
    public const string VERSION = 'Wersja musi być dodatnią liczbą całkowitą.';

    private const string UUID = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D';

    public static function validateNameAndSlug(ExecutionContextInterface $context, mixed $name, mixed $slug): void
    {
        if (\is_string($name)) {
            try {
                CategoryName::of($name);
            } catch (\InvalidArgumentException) {
                $context->buildViolation(self::NAME)->atPath('name')->addViolation();
            }
        }
        if (\is_string($slug)) {
            try {
                new CategorySlug($slug);
            } catch (\InvalidArgumentException) {
                $context->buildViolation(self::SLUG)->atPath('slug')->addViolation();
            }
        }
    }

    /**
     * null is a root; anything else must be the id of a category.
     */
    public static function validateParent(ExecutionContextInterface $context, mixed $parentId): void
    {
        if (null !== $parentId && (!\is_string($parentId) || 1 !== preg_match(self::UUID, $parentId))) {
            $context->buildViolation(new ParentCategoryNotFound()->getMessage())->atPath('parentId')->addViolation();
        }
    }
}
