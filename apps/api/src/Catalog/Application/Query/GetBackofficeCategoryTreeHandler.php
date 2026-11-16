<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\Port\CategoryProductCounts;
use App\Catalog\Domain\Category\CategoryRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The tree of the panel in the order of the store. The products are counted only when asked for: one bounded count
 * per category (the tree is small), never a count of the whole catalog.
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetBackofficeCategoryTreeHandler
{
    public const int COUNT_LIMIT = 1000;

    public function __construct(private CategoryRepository $categories, private CategoryProductCounts $products)
    {
    }

    /**
     * @return list<BackofficeCategoryNode>
     */
    public function __invoke(GetBackofficeCategoryTree $query): array
    {
        return CategoryTreeBuilder::buildBackoffice(
            $this->categories->all(),
            $query->withCounts ? $this->products->countAll(self::COUNT_LIMIT) : null,
        );
    }
}
