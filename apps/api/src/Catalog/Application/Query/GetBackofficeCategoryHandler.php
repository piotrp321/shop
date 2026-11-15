<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Domain\Category\CategoryTree;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetBackofficeCategoryHandler
{
    public function __construct(private CategoryRepository $categories)
    {
    }

    /**
     * @throws CategoryNotFound
     */
    public function __invoke(GetBackofficeCategory $query): BackofficeCategoryView
    {
        $tree = CategoryTree::of($this->categories->all());
        $category = $tree->get(CategoryId::fromString($query->categoryId));

        return new BackofficeCategoryView(
            $category->id()->toString(),
            $category->name()->value(),
            $category->slug()->value(),
            $tree->slugPath($category->id())->toString(),
            $category->parentId()?->toString(),
            $category->version(),
        );
    }
}
