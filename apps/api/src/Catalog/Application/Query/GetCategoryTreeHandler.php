<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Domain\Category\CategoryRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
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
        return CategoryTreeBuilder::build($this->categories->all());
    }
}
