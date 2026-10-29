<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GetCategoryTreeHandler
{
    public function __construct(private CategoryRepository $categories)
    {
    }

    /**
     * @return list<CategoryNode>
     */
    public function __invoke(GetCategoryTree $query): array
    {
        $roots = [];
        $childrenByParent = [];
        foreach ($this->categories->all() as $category) {
            $parentId = $category->parentId();
            if (null === $parentId) {
                $roots[] = $category;
            } else {
                $childrenByParent[$parentId->toString()][] = $category;
            }
        }

        return $this->nodes($roots, $childrenByParent, new \Collator('pl_PL'));
    }

    /**
     * @param list<Category>                $siblings
     * @param array<string, list<Category>> $childrenByParent
     *
     * @return list<CategoryNode>
     */
    private function nodes(array $siblings, array $childrenByParent, \Collator $collator): array
    {
        usort($siblings, static fn (Category $a, Category $b): int => $collator->compare($a->name()->value(), $b->name()->value())
            ?: strcmp($a->id()->toString(), $b->id()->toString()));

        return array_map(
            fn (Category $category): CategoryNode => new CategoryNode(
                $category->id()->toString(),
                $category->name()->value(),
                $this->nodes($childrenByParent[$category->id()->toString()] ?? [], $childrenByParent, $collator),
            ),
            $siblings,
        );
    }
}
